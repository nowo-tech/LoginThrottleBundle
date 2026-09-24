<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Simulates consecutive login requests served by the same service instances with no kernel reset
 * (FrankenPHP worker mode without services_resetter).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoginAttemptRepositoryWorkerModeTest extends TestCase
{
    private Configuration $configuration;
    private Connection $connection;
    private WorkerModeManagerRegistry $registry;

    protected function setUp(): void
    {
        $this->configuration = ORMSetup::createAttributeMetadataConfiguration(
            [\dirname(__DIR__, 2) . '/src/Entity'],
            true,
        );
        if (\PHP_VERSION_ID >= 80400) {
            $this->configuration->enableNativeLazyObjects(true);
        }
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $this->configuration);
        $this->registry = new WorkerModeManagerRegistry(
            fn (): EntityManager => new EntityManager($this->connection, $this->configuration),
        );
        $this->createSchema();
    }

    public function testConsecutiveRequestsDoNotAccumulateAttemptsInIdentityMap(): void
    {
        $limiter = new DatabaseRateLimiter(new LoginAttemptRepository($this->registry), 2, 600);

        $alice = $limiter->consume($this->loginRequest('10.0.0.1', 'alice'));
        $this->assertSame(0, $this->registry->current()->getUnitOfWork()->size());

        $bob = $limiter->consume($this->loginRequest('10.0.0.2', 'bob'));
        $this->assertSame(0, $this->registry->current()->getUnitOfWork()->size());

        $aliceAgain = $limiter->consume($this->loginRequest('10.0.0.1', 'alice'));
        $this->assertSame(0, $this->registry->current()->getUnitOfWork()->size());

        $this->assertTrue($alice->isAccepted());
        $this->assertSame(1, $alice->getRemainingTokens());
        $this->assertTrue($bob->isAccepted());
        $this->assertSame(1, $bob->getRemainingTokens());
        $this->assertFalse($aliceAgain->isAccepted());
        $this->assertSame(0, $aliceAgain->getRemainingTokens());
    }

    public function testFailedFlushDoesNotLeaveTheEntityManagerClosedForTheNextRequest(): void
    {
        $repository = new LoginAttemptRepository($this->registry);
        $first = $this->registry->current();

        $this->connection->executeStatement('DROP TABLE login_attempts');

        try {
            $repository->recordAttempt('10.0.0.1', 'alice');
            $this->fail('The insert should fail while the table is missing.');
        } catch (\Throwable) {
        }

        $this->assertFalse($first->isOpen());
        $this->assertNotSame($first, $this->registry->current());

        $this->createSchema();
        $attempt = $repository->recordAttempt('10.0.0.2', 'bob');

        $this->assertNotNull($attempt->getId());
        $this->assertSame(1, $repository->countAttempts('10.0.0.2', 'bob', 600));
        $this->assertTrue($this->registry->current()->isOpen());
    }

    public function testQueriesUseFreshManagerAfterReset(): void
    {
        $repository = new LoginAttemptRepository($this->registry);
        $repository->recordAttempt('10.0.0.9', 'dave');

        $closed = $this->registry->current();
        $closed->close();
        $this->assertFalse($closed->isOpen());

        $this->assertSame(1, $repository->countAttempts('10.0.0.9', 'dave', 600));
        $this->assertNotSame($closed, $this->registry->current());
        $this->assertTrue($this->registry->current()->isOpen());
        $this->assertSame(1, $repository->clearAttempts('10.0.0.9', 'dave'));
    }

    public function testGetAttemptsReturnsDetachedEntities(): void
    {
        $repository = new LoginAttemptRepository($this->registry);
        $repository->recordAttempt('10.0.0.3', 'carol');

        $attempts = $repository->getAttempts('10.0.0.3', 'carol', 600);

        $this->assertCount(1, $attempts);
        $this->assertSame('carol', $attempts[0]->getUsername());
        $this->assertSame(0, $this->registry->current()->getUnitOfWork()->size());
    }

    public function testClosedManagerUnknownToRegistryIsNotReset(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects($this->once())->method('persist');
        $closed->expects($this->never())->method('detach');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['other' => 'doctrine.orm.other_entity_manager']);
        $registry->method('getManager')->with('other')->willReturn($this->createMock(EntityManagerInterface::class));
        $registry->expects($this->never())->method('resetManager');

        (new LoginAttemptRepository($registry))->recordAttempt('10.0.0.4', null);
    }

    public function testResetReturningNonOrmManagerKeepsTheClosedManager(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects($this->once())->method('persist');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->with('default')->willReturn($closed);
        $registry->expects($this->once())->method('resetManager')->willReturn($this->createMock(ObjectManager::class));

        (new LoginAttemptRepository($registry))->recordAttempt('10.0.0.5', null);
    }

    public function testNonOrmManagerForClassThrows(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->createMock(ObjectManager::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Expected an ORM EntityManager');

        (new LoginAttemptRepository($registry))->countAttempts('10.0.0.6', null, 600);
    }

    private function loginRequest(string $ip, string $username): Request
    {
        return Request::create('/login', 'POST', ['_username' => $username], server: ['REMOTE_ADDR' => $ip]);
    }

    private function createSchema(): void
    {
        $entityManager = $this->registry->current();
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(LoginAttempt::class)]);
    }
}

/**
 * Registry whose resetManager() replaces the manager, like DoctrineBundle does for non-lazy managers.
 */
final class WorkerModeManagerRegistry implements ManagerRegistry
{
    private EntityManager $entityManager;

    /**
     * @param \Closure(): EntityManager $factory
     */
    public function __construct(private readonly \Closure $factory)
    {
        $this->entityManager = ($this->factory)();
    }

    public function current(): EntityManager
    {
        return $this->entityManager;
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function getConnections(): array
    {
        return ['default' => $this->entityManager->getConnection()];
    }

    public function getConnectionNames(): array
    {
        return ['default' => 'doctrine.dbal.default_connection'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->entityManager;
    }

    public function getManagers(): array
    {
        return ['default' => $this->entityManager];
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        $this->entityManager = ($this->factory)();

        return $this->entityManager;
    }

    public function getManagerNames(): array
    {
        return ['default' => 'doctrine.orm.default_entity_manager'];
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->entityManager->getRepository($persistentObject);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        return LoginAttempt::class === $class ? $this->entityManager : null;
    }
}
