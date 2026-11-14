<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\DependencyInjection\NowoLoginThrottleExtension;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Event\LoginThrottledEvent;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProvider;
use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProviderInterface;
use Nowo\LoginThrottleBundle\Tests\Integration\Kernel\MappingTestKernel;
use Nowo\LoginThrottleBundle\Tests\Support\DenyAllStore;
use Nowo\LoginThrottleBundle\Tests\Support\EntityFreeLoginAttemptStore;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * doctrine.register_mapping in a booted host with Doctrine ORM auto_mapping.
 */
final class DoctrineMappingKernelTest extends TestCase
{
    private ?MappingTestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(MappingTestKernel::baseDir());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function mappingCases(): iterable
    {
        yield 'true, cache' => [['doctrine' => ['register_mapping' => true]], true];
        yield 'true, database' => [['doctrine' => ['register_mapping' => true], 'storage' => 'database'], true];
        yield 'false, cache' => [['doctrine' => ['register_mapping' => false]], false];
        yield 'auto, cache' => [['doctrine' => ['register_mapping' => 'auto']], false];
        yield 'auto, database' => [['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database'], true];
        yield 'auto, firewalls all cache' => [['doctrine' => ['register_mapping' => 'auto'], 'firewalls' => ['main' => ['storage' => 'cache'], 'api' => ['storage' => 'cache']]], false];
        yield 'auto, one database firewall' => [['doctrine' => ['register_mapping' => 'auto'], 'firewalls' => ['main' => ['storage' => 'cache'], 'api' => ['storage' => 'database']]], true];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('mappingCases')]
    public function testEntityIsMappedOnlyWhenEnabled(array $config, bool $mapped): void
    {
        $entityManager = $this->entityManager($config);

        $classes = array_map(
            static fn (ClassMetadata $metadata): string => $metadata->getName(),
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );

        self::assertSame($mapped, \in_array(LoginAttempt::class, $classes, true));
        self::assertSame($mapped, $this->container()->getParameter(NowoLoginThrottleExtension::MAPPING_ENABLED_PARAMETER));
    }

    public function testAutoWithCacheStorageNeedsNoTable(): void
    {
        $entityManager = $this->entityManager(['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'cache']);

        $sql = (new SchemaTool($entityManager))->getCreateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata());

        self::assertSame([], array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'login_attempts'))));
        self::assertFalse($this->container()->has(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID));

        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('nowo:login-throttle:cleanup'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('not available', $tester->getDisplay());
    }

    public function testAutoWithDatabaseStorageThrottlesThroughTheTable(): void
    {
        $entityManager = $this->entityManager(['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database']);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new LoginAttempt('127.0.0.1', 'user'));
        $entityManager->flush();

        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('nowo:login-throttle:cleanup'));

        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertTrue($this->container()->has(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID));
    }

    public function testDatabaseLimiterUsesTheRepositoryPortAliasedByTheHost(): void
    {
        $config = ['storage' => 'database', 'max_count_attempts' => 2];
        $this->kernel = new MappingTestKernel($config, 'host-store', static function (ContainerBuilder $container): void {
            $container->register('app.login_attempt_store', InMemoryLoginAttemptStore::class)->setPublic(true);
            $container->setAlias(LoginAttemptRepositoryInterface::class, 'app.login_attempt_store');
        });
        $this->kernel->boot();

        $limiter = $this->container()->get(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID);
        self::assertInstanceOf(DatabaseRateLimiter::class, $limiter);
        $store = $this->container()->get('app.login_attempt_store');
        self::assertInstanceOf(InMemoryLoginAttemptStore::class, $store);

        $request = Request::create('/login', 'POST', ['_username' => 'alice'], server: ['REMOTE_ADDR' => '10.0.0.1']);
        $limiter->consume($request);

        self::assertSame(1, $store->countAttempts('10.0.0.1', 'alice', 600), 'the attempt went to the host store, not the bundle table');
    }

    /**
     * Standard host (3.14): bundle table, attribute mapping, the kernel's event dispatcher; a lockout reaches a
     * listener as a scalar event after the row was written.
     */
    public function testStandardHostDispatchesLoginThrottledAfterTheWrite(): void
    {
        $entityManager = $this->entityManager(['storage' => 'database', 'max_count_attempts' => 1]);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $rowsSeen = [];
        $events = [];
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(LoginThrottledEvent::class, static function (LoginThrottledEvent $event) use (&$events, &$rowsSeen, $entityManager): void {
            $events[] = $event;
            $rowsSeen[] = (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM login_attempts');
        });

        $limiter = $this->container()->get(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID);
        self::assertInstanceOf(DatabaseRateLimiter::class, $limiter);
        $limiter->consume(Request::create('/login', 'POST', ['_username' => 'alice'], server: ['REMOTE_ADDR' => '10.0.0.1']));

        self::assertCount(1, $events);
        self::assertSame(['10.0.0.1', 'alice', LoginThrottledEvent::SCOPE_USERNAME, true], [$events[0]->ipAddress, $events[0]->username, $events[0]->scope, $events[0]->lockoutStarted]);
        self::assertSame([1], $rowsSeen, 'the attempt row exists when the listener runs');
    }

    /**
     * DDD host (3.14): no bundle table (register_mapping: false), storage database through a store that implements
     * only the entity-free port; limiter, status provider and cleanup command all use it.
     */
    public function testDddHostWithoutTheBundleTableUsesItsEntityFreeStore(): void
    {
        $config = ['doctrine' => ['register_mapping' => false], 'storage' => 'database', 'max_count_attempts' => 2];
        $this->kernel = new MappingTestKernel($config, 'ddd-store', static function (ContainerBuilder $container): void {
            $container->register('app.login_attempt_store', EntityFreeLoginAttemptStore::class)->setPublic(true);
            $container->setAlias(LoginAttemptStoreInterface::class, 'app.login_attempt_store');
            // public only to fetch it from the test
            $container->setAlias(LoginThrottleStatusProviderInterface::class, LoginThrottleStatusProvider::class)->setPublic(true);
        });
        $this->kernel->boot();

        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $entityManager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $classes = array_map(static fn (ClassMetadata $metadata): string => $metadata->getName(), $entityManager->getMetadataFactory()->getAllMetadata());
        self::assertNotContains(LoginAttempt::class, $classes, 'no login_attempts table in the host schema');

        $events = [];
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(LoginThrottledEvent::class, static function (LoginThrottledEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $limiter = $this->container()->get(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID);
        self::assertInstanceOf(DatabaseRateLimiter::class, $limiter);
        $store = $this->container()->get('app.login_attempt_store');
        self::assertInstanceOf(EntityFreeLoginAttemptStore::class, $store);

        $request = Request::create('/login', 'POST', ['_username' => 'alice'], server: ['REMOTE_ADDR' => '10.0.0.1']);
        self::assertTrue($limiter->consume($request)->isAccepted());
        self::assertFalse($limiter->consume($request)->isAccepted());

        self::assertSame(2, $store->countAttempts('10.0.0.1', 'alice', 600));
        self::assertCount(1, $events);

        $status = $this->container()->get(LoginThrottleStatusProviderInterface::class);
        self::assertInstanceOf(LoginThrottleStatusProviderInterface::class, $status);
        $throttle = $status->status('main', '10.0.0.1', 'alice');
        self::assertTrue($throttle->tracked);
        self::assertTrue($throttle->blocked);

        $store->advance(7200);
        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('nowo:login-throttle:cleanup'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $store->countAttemptsByIp('10.0.0.1', 100000), 'the cleanup command pruned the host store');
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function decoratedPorts(): iterable
    {
        yield 'deprecated port' => [LoginAttemptRepositoryInterface::class];
        yield 'entity-free port' => [LoginAttemptStoreInterface::class];
        yield 'bundle repository id' => [LoginAttemptRepository::class];
    }

    /**
     * Audit PN M-1: a host decorator of a store port (or of the repository id) drives the limiter and the status
     * provider, as in 3.13.1. A stricter decorator must keep refusing.
     *
     * @param class-string $decorated
     */
    #[DataProvider('decoratedPorts')]
    public function testHostDecoratorOfTheStoreIsApplied(string $decorated): void
    {
        $config = ['storage' => 'database', 'max_count_attempts' => 3];
        $this->kernel = new MappingTestKernel($config, 'decorator-' . md5($decorated), static function (ContainerBuilder $container) use ($decorated): void {
            $container->register('app.deny_all', DenyAllStore::class)->setDecoratedService($decorated);
            $container->setAlias(LoginThrottleStatusProviderInterface::class, LoginThrottleStatusProvider::class)->setPublic(true);
        });
        $this->kernel->boot();

        $limiter = $this->container()->get(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID);
        self::assertInstanceOf(DatabaseRateLimiter::class, $limiter);
        $request = Request::create('/login', 'POST', ['_username' => 'alice'], server: ['REMOTE_ADDR' => '10.0.0.1']);
        self::assertFalse($limiter->consume($request)->isAccepted(), 'the stricter decorator refuses the first attempt');

        $status = $this->container()->get(LoginThrottleStatusProviderInterface::class);
        self::assertInstanceOf(LoginThrottleStatusProviderInterface::class, $status);
        self::assertTrue($status->status('main', '10.0.0.1', 'alice')->blocked);
    }

    /**
     * Audit PN L-1: a port aliased to a service that does not exist fails the build (3.13.1 did), instead of falling
     * back to another store.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function ports(): iterable
    {
        yield 'deprecated port' => [LoginAttemptRepositoryInterface::class];
        yield 'entity-free port' => [LoginAttemptStoreInterface::class];
    }

    /**
     * @param class-string $port
     */
    #[DataProvider('ports')]
    public function testPortAliasedToAMissingServiceFailsTheBuild(string $port): void
    {
        $this->kernel = new MappingTestKernel(['storage' => 'database'], 'typo-' . md5($port), static function (ContainerBuilder $container) use ($port): void {
            $container->setAlias($port, 'app.typo_store');
        });

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/app\.typo_store/');

        $this->kernel->boot();
    }

    public function testFalseWithDatabaseStorageFailsAtContainerBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"nowo_login_throttle.doctrine.register_mapping: false" requires storage "cache"');

        $this->boot(['doctrine' => ['register_mapping' => false], 'storage' => 'database']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config): void
    {
        $this->kernel = new MappingTestKernel($config, md5(serialize($config)));
        $this->kernel->boot();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function entityManager(array $config): EntityManagerInterface
    {
        $this->boot($config);
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $entityManager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function kernel(): MappingTestKernel
    {
        self::assertNotNull($this->kernel);

        return $this->kernel;
    }

    private function container(): ContainerInterface
    {
        return $this->kernel()->getContainer();
    }
}
