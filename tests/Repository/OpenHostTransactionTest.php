<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attempt recorded inside a transaction the host left open runs in a savepoint: a failed insert must not abort the
 * host's transaction (PostgreSQL SQLSTATE 25P02). SQLite always; MySQL / PostgreSQL when LOGIN_THROTTLE_TEST_MYSQL_URL
 * / LOGIN_THROTTLE_TEST_PGSQL_URL point at a throwaway database (re-runnable: the tables are dropped first).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class OpenHostTransactionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function platforms(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['LOGIN_THROTTLE_TEST_MYSQL_URL'];
        yield 'postgresql' => ['LOGIN_THROTTLE_TEST_PGSQL_URL'];
    }

    #[DataProvider('platforms')]
    public function testAFailedInsertDoesNotAbortTheHostsTransaction(string $source): void
    {
        $entityManager = $this->entityManager($source);
        $connection = $entityManager->getConnection();
        // a fixed pre-insert id: the second attempt violates the primary key on every platform
        $entityManager->getClassMetadata(LoginAttempt::class)->setIdGenerator(new class extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                return 7;
            }
        });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $repository = new LoginAttemptRepository($registry);

        $connection->beginTransaction();
        $connection->insert('host_w0_note', ['id' => 1, 'body' => 'before the attempt']);
        $repository->recordAttempt('10.0.0.1', 'alice');

        try {
            $repository->recordAttempt('10.0.0.1', 'alice');
            $this->fail('The duplicate id must be rejected.');
        } catch (UniqueConstraintViolationException) {
        }
        $connection->insert('host_w0_note', ['id' => 2, 'body' => 'after the failed attempt']);
        $connection->commit();

        $this->assertFalse($connection->isTransactionActive());
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_w0_note'));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM login_attempts'));
        $this->assertTrue($entityManager->isOpen());

        $connection->executeStatement('DROP TABLE host_w0_note');
        $connection->executeStatement('DROP TABLE login_attempts');
    }

    /**
     * KY-D-L-M1: the IDENTITY id is read before RELEASE SAVEPOINT, which resets the last insert id on MySQL / MariaDB.
     */
    #[DataProvider('platforms')]
    public function testTheIdentityIdInsideAnOpenTransactionIsTheRowId(string $source): void
    {
        $entityManager = $this->entityManager($source);
        $connection = $entityManager->getConnection();
        $this->assertTrue($entityManager->getClassMetadata(LoginAttempt::class)->idGenerator->isPostInsertGenerator(), 'the real IDENTITY generator');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $repository = new LoginAttemptRepository($registry);

        $connection->beginTransaction();
        $connection->insert('host_w0_note', ['id' => 1, 'body' => 'host row']);
        $first = $repository->recordAttempt('10.0.0.1', 'alice');
        $second = $repository->recordAttempt('10.0.0.2', 'bob');
        $connection->commit();

        $rows = array_map(
            static fn (array $row): array => [(int) $row['id'], $row['username']],
            $connection->fetchAllAssociative('SELECT id, username FROM login_attempts ORDER BY id'),
        );
        $this->assertSame([[$first->getId(), 'alice'], [$second->getId(), 'bob']], $rows);
        $this->assertGreaterThan(0, $first->getId());
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_w0_note'));
    }

    /**
     * KY-D-I2: when the savepoint is already gone (MySQL rolls back the whole transaction on a deadlock), the original
     * error is reported, not the failed ROLLBACK TO SAVEPOINT.
     */
    public function testTheOriginalErrorIsRethrownWhenTheSavepointIsGone(): void
    {
        $entityManager = $this->entityManager('sqlite');
        $connection = $entityManager->getConnection();
        $entityManager->getClassMetadata(LoginAttempt::class)->setIdGenerator(new class extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                // the server ends the whole transaction, savepoints included
                $em->getConnection()->executeStatement('ROLLBACK');

                throw new \RuntimeException('deadlock: the transaction was rolled back');
            }

            public function isPostInsertGenerator(): bool
            {
                return true;
            }
        });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        $connection->beginTransaction();

        try {
            (new LoginAttemptRepository($registry))->recordAttempt('10.0.0.1', 'alice');
            $this->fail('The original error must be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('deadlock: the transaction was rolled back', $exception->getMessage());
        } finally {
            try {
                $connection->rollBack(); // the server already ended the transaction
            } catch (Exception) {
            }
        }
    }

    /**
     * KY-D-I1: the bundle's savepoint name is unique per call. On MySQL / MariaDB a SAVEPOINT with an existing name
     * replaces it, so the former fixed name would have destroyed a host savepoint of that name.
     */
    #[DataProvider('platforms')]
    public function testAHostSavepointWithTheBundlesFormerNameSurvivesAnAttempt(string $source): void
    {
        $entityManager = $this->entityManager($source);
        $connection = $entityManager->getConnection();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $repository = new LoginAttemptRepository($registry);

        $connection->beginTransaction();
        $connection->insert('host_w0_note', ['id' => 1, 'body' => 'kept']);
        $connection->createSavepoint('nowo_login_throttle_insert');
        $connection->insert('host_w0_note', ['id' => 2, 'body' => 'rolled back by the host']);
        $repository->recordAttempt('10.0.0.1', 'alice');
        $repository->recordAttempt('10.0.0.2', 'bob');
        $connection->rollbackSavepoint('nowo_login_throttle_insert');
        $connection->commit();

        $this->assertSame([1], array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM host_w0_note')));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM login_attempts'), 'the attempts were after the host savepoint');
    }

    private function entityManager(string $source): EntityManager
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        if ('sqlite' !== $source) {
            $url = getenv($source);
            if (!\is_string($url) || '' === $url) {
                $this->markTestSkipped($source . ' is not set.');
            }
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        }

        $configuration = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection($params, $configuration);
        $connection->executeStatement('DROP TABLE IF EXISTS host_w0_note');
        $connection->executeStatement('DROP TABLE IF EXISTS login_attempts');
        $connection->executeStatement('CREATE TABLE host_w0_note (id INT NOT NULL PRIMARY KEY, body VARCHAR(40) NOT NULL)');
        $entityManager = new EntityManager($connection, $configuration);
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(LoginAttempt::class)]);

        return $entityManager;
    }
}
