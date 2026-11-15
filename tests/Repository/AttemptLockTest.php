<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Transaction\AttemptLockRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * {@see LoginAttemptRepository::withAttemptLock()} (3.15) on SQLite: one transaction per unit, the host's open
 * transaction left to the host, the locking-read copy, and the limiter deciding and resetting through it. The real
 * servers are covered by tests/Database.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class AttemptLockTest extends TestCase
{
    public function testAUnitRunsInItsOwnTransactionOnTheSharedRepository(): void
    {
        $entityManager = $this->entityManager();
        $repository = $this->repository($entityManager);
        $connection = $entityManager->getConnection();

        $seen = $repository->withAttemptLock('10.0.0.1', static function (LoginAttemptRepositoryInterface|LoginAttemptStoreInterface $store) use ($connection): LoginAttemptRepositoryInterface|LoginAttemptStoreInterface {
            self::assertTrue($connection->isTransactionActive(), 'inside the unit transaction');
            $store->recordAttemptView('10.0.0.1', 'alice');

            return $store;
        });

        $this->assertSame($repository, $seen, 'no locking reads: the shared repository itself');
        $this->assertFalse($connection->isTransactionActive());
        $this->assertSame(1, $repository->countAttempts('10.0.0.1', 'alice', 600));
    }

    public function testAFailedUnitKeepsNothing(): void
    {
        $entityManager = $this->entityManager();
        $repository = $this->repository($entityManager);

        try {
            $repository->withAttemptLock('10.0.0.1', static function (LoginAttemptRepositoryInterface|LoginAttemptStoreInterface $store): never {
                $store->recordAttemptView('10.0.0.1', 'alice');

                throw new \DomainException('failed after the insert');
            });
            $this->fail('The failure must reach the caller.');
        } catch (\DomainException) {
        }

        $this->assertFalse($entityManager->getConnection()->isTransactionActive());
        $this->assertSame(0, $repository->countAttemptsByIp('10.0.0.1', 600));
        $this->assertTrue($entityManager->isOpen(), 'the entity manager is never closed or reset');
    }

    public function testInsideTheHostsTransactionTheUnitReadsThroughALockingCopy(): void
    {
        $entityManager = $this->entityManager();
        $repository = $this->repository($entityManager, new AttemptLockRunner(lockingReads: true));
        $connection = $entityManager->getConnection();
        $repository->recordAttemptView('10.0.0.1', 'alice');
        $repository->recordAttemptView('10.0.0.1', null);

        $connection->beginTransaction();
        [$store, $counts] = $repository->withAttemptLock('10.0.0.1', static function (LoginAttemptRepositoryInterface|LoginAttemptStoreInterface $store): array {
            $store->recordAttemptView('10.0.0.1', 'alice');

            return [$store, [
                $store->countAttempts('10.0.0.1', 'alice', 600),
                $store->countAttemptsByIp('10.0.0.1', 600),
                $store->countAttemptsWithoutUsername('10.0.0.1', 600),
                $store->clearAttempts('10.0.0.1', 'alice'),
            ]];
        });

        $this->assertNotSame($repository, $store, 'a per-call copy reads with locking reads');
        $this->assertInstanceOf(LoginAttemptRepository::class, $store);
        $this->assertSame([2, 3, 1, 2], $counts);
        $this->assertSame(1, $connection->getTransactionNestingLevel(), 'the host\'s transaction stays open, at its level');
        $connection->rollBack();
        $this->assertSame(2, $repository->countAttemptsByIp('10.0.0.1', 600), 'the host rolled the unit back with its own work');
    }

    /**
     * CodeRabbit (PR #30): a unit that fails after recording inside the host's transaction keeps nothing, even when the
     * host catches the failure and commits.
     */
    public function testAFailedUnitInsideTheHostsTransactionKeepsNothingWhenTheHostCommits(): void
    {
        $entityManager = $this->entityManager();
        $repository = $this->repository($entityManager);
        $connection = $entityManager->getConnection();

        $connection->beginTransaction();

        try {
            $repository->withAttemptLock('10.0.0.1', static function (LoginAttemptRepositoryInterface|LoginAttemptStoreInterface $store): never {
                $store->recordAttemptView('10.0.0.1', 'alice');

                throw new \DomainException('failed after the insert');
            });
            $this->fail('The failure must reach the caller.');
        } catch (\DomainException) {
        }
        $this->assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();

        $this->assertSame(0, $repository->countAttemptsByIp('10.0.0.1', 600));
    }

    public function testTheLimiterDecidesAndResetsUnderTheLock(): void
    {
        $entityManager = $this->entityManager();
        $repository = $this->repository($entityManager);
        $limiter = new DatabaseRateLimiter($repository, 2, 600);
        $request = Request::create('/login', 'POST', ['_username' => 'alice'], [], [], ['REMOTE_ADDR' => '10.0.0.1']);

        $this->assertTrue($limiter->consume($request)->isAccepted());
        $this->assertFalse($limiter->consume($request)->isAccepted(), 'the second attempt starts the lockout');
        $this->assertFalse($limiter->consume($request)->isAccepted());
        $this->assertSame(2, $repository->countAttempts('10.0.0.1', 'alice', 600));

        $limiter->reset($request);

        $this->assertSame(0, $repository->countAttempts('10.0.0.1', 'alice', 600));
        $this->assertSame(1, $repository->countAttemptsByIp('10.0.0.1', 600));
        $this->assertFalse($entityManager->getConnection()->isTransactionActive());
    }

    private function entityManager(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(LoginAttempt::class)]);

        return $entityManager;
    }

    private function repository(EntityManager $entityManager, ?AttemptLockRunner $attemptLock = null): LoginAttemptRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new LoginAttemptRepository($registry, null, $attemptLock);
    }
}
