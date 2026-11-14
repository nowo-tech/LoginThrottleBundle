<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\DependencyInjection;

use Nowo\LoginThrottleBundle\DependencyInjection\Compiler\AttemptStorePass;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Tests\Support\EntityFreeLoginAttemptStore;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Which store the bundle uses (3.14): the host's entity-free port wins, then the host's deprecated port, then the
 * bundle repository.
 */
final class AttemptStorePassTest extends TestCase
{
    public function testDefaultIsTheBundleRepository(): void
    {
        $container = $this->bundleDefaults();

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));
        self::assertSame(LoginAttemptRepository::class, $this->target($container));
    }

    public function testHostLegacyPortKeepsWorking(): void
    {
        $container = $this->bundleDefaults();
        $container->register('app.legacy_store', InMemoryLoginAttemptStore::class);
        $container->setAlias(LoginAttemptRepositoryInterface::class, 'app.legacy_store');

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptRepositoryInterface::class, $this->resolved($container));
    }

    public function testHostEntityFreePortIsUsed(): void
    {
        $container = $this->bundleDefaults();
        $container->register('app.store', EntityFreeLoginAttemptStore::class);
        $container->setAlias(LoginAttemptStoreInterface::class, 'app.store');

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));
        self::assertSame('app.store', $this->target($container));
    }

    public function testEntityFreePortWinsWhenBothAreAliased(): void
    {
        $container = $this->bundleDefaults();
        $container->register('app.store', EntityFreeLoginAttemptStore::class);
        $container->register('app.legacy_store', InMemoryLoginAttemptStore::class);
        $container->setAlias(LoginAttemptStoreInterface::class, 'app.store');
        $container->setAlias(LoginAttemptRepositoryInterface::class, 'app.legacy_store');

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));
        self::assertSame('app.store', $this->target($container));
    }

    public function testServiceRegisteredUnderTheInterfaceIdAndAliasChains(): void
    {
        $container = new ContainerBuilder();
        $container->register(LoginAttemptStoreInterface::class, EntityFreeLoginAttemptStore::class);
        $container->setAlias('app.chain', LoginAttemptStoreInterface::class);
        $container->setAlias(LoginAttemptRepositoryInterface::class, 'app.chain');

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));
    }

    public function testNoStoreRemovesTheAliasAndKeepsCacheOnlyHostsBuilding(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias(AttemptStorePass::STORE_SERVICE_ID, 'missing');

        (new AttemptStorePass())->process($container);

        self::assertFalse($container->hasAlias(AttemptStorePass::STORE_SERVICE_ID));
    }

    public function testNoStoreWithADatabaseLimiterFails(): void
    {
        $container = new ContainerBuilder();
        $container->register('limiter')->addTag(AttemptStorePass::DATABASE_LIMITER_TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('or alias ' . LoginAttemptStoreInterface::class . ' to your own store');

        (new AttemptStorePass())->process($container);
    }

    public function testRejectsAServiceThatImplementsNeitherPort(): void
    {
        $container = $this->bundleDefaults();
        $container->register('app.wrong', \ArrayObject::class);
        $container->setAlias(LoginAttemptStoreInterface::class, 'app.wrong');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement ' . LoginAttemptStoreInterface::class);

        (new AttemptStorePass())->process($container);
    }

    public function testLeavesUnresolvableClassesAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.store_class', EntityFreeLoginAttemptStore::class);
        $container->register('app.parameterised', '%app.store_class%');
        $container->register('app.factory')->setFactory([EntityFreeLoginAttemptStore::class, 'create']);
        $container->register('app.unknown', 'App\\Missing\\Store');
        $container->setAlias(LoginAttemptStoreInterface::class, 'app.parameterised');
        $container->setAlias(LoginAttemptRepositoryInterface::class, 'app.factory');

        (new AttemptStorePass())->process($container);
        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));

        $container->setAlias(LoginAttemptStoreInterface::class, 'app.unknown');
        (new AttemptStorePass())->process($container);
        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));

        $container->removeAlias(LoginAttemptStoreInterface::class);
        (new AttemptStorePass())->process($container);
        self::assertSame(LoginAttemptRepositoryInterface::class, $this->resolved($container));
    }

    /**
     * Audit PN M-1: a decorated port counts as host-provided and the alias keeps the interface id, so the decorator
     * applies once DecoratorServicePass ran.
     */
    public function testDecoratedLegacyPortWinsOverTheDefaultStore(): void
    {
        $container = $this->bundleDefaults();
        $container->register('app.decorator', EntityFreeLoginAttemptStore::class)->setDecoratedService(LoginAttemptRepositoryInterface::class);

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptRepositoryInterface::class, $this->resolved($container));
    }

    public function testDecoratedEntityFreePortWins(): void
    {
        $container = $this->bundleDefaults();
        $container->register('app.legacy_store', InMemoryLoginAttemptStore::class);
        $container->setAlias(LoginAttemptRepositoryInterface::class, 'app.legacy_store');
        $container->register('app.decorator', EntityFreeLoginAttemptStore::class)->setDecoratedService(LoginAttemptStoreInterface::class);

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));
    }

    public function testDecoratedPortWithoutServiceBehindItIsStillUsed(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.decorator', EntityFreeLoginAttemptStore::class)->setDecoratedService(LoginAttemptStoreInterface::class);

        (new AttemptStorePass())->process($container);

        self::assertSame(LoginAttemptStoreInterface::class, $this->resolved($container));
    }

    /**
     * Audit PN L-1: a port aliased to a missing service fails the build instead of falling back.
     */
    public function testPortAliasedToAMissingServiceFails(): void
    {
        $container = $this->bundleDefaults();
        $container->setAlias(LoginAttemptRepositoryInterface::class, 'app.typo_store');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is aliased to "app.typo_store", which does not exist');

        (new AttemptStorePass())->process($container);
    }

    private function target(ContainerBuilder $container): string
    {
        $id = AttemptStorePass::STORE_SERVICE_ID;
        while ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }

    private function bundleDefaults(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(LoginAttemptRepository::class, LoginAttemptRepository::class);
        $container->setAlias(LoginAttemptStoreInterface::class, LoginAttemptRepository::class);
        $container->setAlias(LoginAttemptRepositoryInterface::class, LoginAttemptRepository::class);

        return $container;
    }

    private function resolved(ContainerBuilder $container): string
    {
        return (string) $container->getAlias(AttemptStorePass::STORE_SERVICE_ID);
    }
}
