<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Tests\Fixtures\Entity\HostAccount;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rule 6: in a standard install LoginAttempt lives in the host's default EntityManager. A failed login must record
 * its attempt without committing what the host left pending in that manager.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class HostEntityManagerIsolationTest extends TestCase
{
    private EntityManager $entityManager;

    private ManagerRegistry&MockObject $registry;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [\dirname(__DIR__, 2) . '/src/Entity', \dirname(__DIR__, 2) . '/tests/Fixtures/Entity'],
            true,
        );
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(LoginAttempt::class),
            $this->entityManager->getClassMetadata(HostAccount::class),
        ]);

        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')->willReturn($this->entityManager);
        $this->registry->expects($this->never())->method('resetManager');
    }

    public function testAFailedLoginDoesNotCommitTheHostsPendingChanges(): void
    {
        $account = new HostAccount('Original');
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        // what a request leaves behind before the login fails: an edited entity and a new one, nothing flushed
        $account->setDisplayName('Edited, never flushed');
        $this->entityManager->persist(new HostAccount('Never submitted'));

        $limiter = new DatabaseRateLimiter(new LoginAttemptRepository($this->registry), 3, 600);
        $limiter->consume(Request::create('/login', 'POST', ['_username' => 'alice'], server: ['REMOTE_ADDR' => '10.0.0.1']));

        $connection = $this->entityManager->getConnection();
        $this->assertSame([['display_name' => 'Original']], $connection->fetchAllAssociative('SELECT display_name FROM host_account ORDER BY id'));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM login_attempts'));

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $this->assertSame(['Original', 'Edited, never flushed'], $unitOfWork->getEntityChangeSet($account)['displayName']);
        $this->assertCount(1, $unitOfWork->getScheduledEntityInsertions());
    }

    public function testRecordedAttemptCarriesItsIdAndStaysUnmanaged(): void
    {
        $repository = new LoginAttemptRepository($this->registry);

        $first = $repository->recordAttempt('10.0.0.2', 'bob');
        $second = $repository->recordAttempt('10.0.0.2', null);

        $this->assertSame(1, $first->getId());
        $this->assertSame(2, $second->getId());
        $this->assertFalse($this->entityManager->contains($first));
        $this->assertSame(0, $this->entityManager->getUnitOfWork()->size());
        $this->assertSame(
            [['ip_address' => '10.0.0.2', 'username' => 'bob', 'blocked' => 0], ['ip_address' => '10.0.0.2', 'username' => null, 'blocked' => 0]],
            array_map(
                static fn (array $row): array => ['ip_address' => $row['ip_address'], 'username' => $row['username'], 'blocked' => (int) $row['blocked']],
                $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM login_attempts ORDER BY id'),
            ),
        );
    }

    public function testAPreInsertIdGeneratorChosenByTheHostIsUsed(): void
    {
        $this->entityManager->getClassMetadata(LoginAttempt::class)->setIdGenerator(new class extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                return 4242;
            }
        });

        $attempt = (new LoginAttemptRepository($this->registry))->recordAttempt('10.0.0.3', 'carol');

        $this->assertSame(4242, $attempt->getId());
        $this->assertSame(4242, (int) $this->entityManager->getConnection()->fetchOne('SELECT id FROM login_attempts'));
    }

    public function testAClosedSharedManagerIsNotResetAndStillRecordsAndReads(): void
    {
        $this->entityManager->close();
        $repository = new LoginAttemptRepository($this->registry);

        $repository->recordAttempt('10.0.0.4', 'dave');

        $this->assertSame(1, $repository->countAttempts('10.0.0.4', 'dave', 600));
        $this->assertSame(1, $repository->clearAttempts('10.0.0.4', 'dave'));
        $this->assertFalse($this->entityManager->isOpen(), 'the host decides when its manager is reset');
    }
}
