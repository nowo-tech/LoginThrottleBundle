<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\DependencyInjection\Compiler;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Resolves the login attempt store the bundle's services use (3.14, REQ-ARCH-001 twin ports).
 *
 * The database limiters, the status provider, the info service, the cleanup command and the limiter factory reference
 * the internal alias {@see self::STORE_SERVICE_ID}. It points to the id of one of the two port interfaces (never to
 * the service behind it, so host decorators of that interface still apply, as in 3.13.1), in order:
 *
 * 1. {@see LoginAttemptStoreInterface} when the host provides it (entity-free port; wins when both are provided);
 * 2. {@see LoginAttemptRepositoryInterface} when the host provides it (deprecated port, kept for existing hosts);
 * 3. {@see LoginAttemptStoreInterface}, else {@see LoginAttemptRepositoryInterface}, i.e. the bundle's
 *    {@see LoginAttemptRepository} (the default, unchanged).
 *
 * "Provided by the host" means aliased to another service than the bundle repository, or decorated (`decorates:`,
 * `#[AsDecorator]`). A port aliased to a service that does not exist fails the build. Without any store
 * (doctrine.register_mapping off and nothing aliased) the alias is removed, the optional consumers get null as before,
 * and a firewall with {@code storage: database} fails the build with the same message as before 3.14.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class AttemptStorePass implements CompilerPassInterface
{
    /**
     * Internal service id of the resolved store.
     */
    public const STORE_SERVICE_ID = 'nowo_login_throttle.attempt_store';

    /**
     * Tag the extension puts on every database limiter it registers.
     */
    public const DATABASE_LIMITER_TAG = 'nowo_login_throttle.database_limiter';

    public function process(ContainerBuilder $container): void
    {
        $decorated = $this->decoratedIds($container);
        $store = $this->target($container, LoginAttemptStoreInterface::class, $decorated);
        $legacy = $this->target($container, LoginAttemptRepositoryInterface::class, $decorated);

        $port = match (true) {
            $this->isHostProvided($store, LoginAttemptStoreInterface::class, $decorated) => LoginAttemptStoreInterface::class,
            $this->isHostProvided($legacy, LoginAttemptRepositoryInterface::class, $decorated) => LoginAttemptRepositoryInterface::class,
            null !== $store => LoginAttemptStoreInterface::class,
            null !== $legacy => LoginAttemptRepositoryInterface::class,
            default => null,
        };

        if (null === $port) {
            if ($container->hasAlias(self::STORE_SERVICE_ID)) {
                $container->removeAlias(self::STORE_SERVICE_ID);
            }

            if ([] !== $container->findTaggedServiceIds(self::DATABASE_LIMITER_TAG)) {
                throw new InvalidArgumentException(\sprintf('"nowo_login_throttle.doctrine.register_mapping: false" requires storage "cache" on every firewall: storage "database" needs the LoginAttempt entity (login_attempts table). Use "auto" or true, or alias %s to your own store.', LoginAttemptStoreInterface::class));
            }

            return;
        }

        $target = LoginAttemptStoreInterface::class === $port ? $store : $legacy;
        if (null !== $target && !isset($decorated[$port])) {
            $this->assertStore($container, $target);
        }

        // the interface id, not the service behind it: DecoratorServicePass (an optimization pass) runs later
        $container->setAlias(self::STORE_SERVICE_ID, $port)->setPublic(false);
    }

    /**
     * Whether the host provides this port: a service other than the bundle repository, or a decorator of the port.
     *
     * @param array<string, true> $decorated
     */
    private function isHostProvided(?string $target, string $port, array $decorated): bool
    {
        return isset($decorated[$port]) || (null !== $target && LoginAttemptRepository::class !== $target);
    }

    /**
     * Ids that some definition decorates.
     *
     * @return array<string, true>
     */
    private function decoratedIds(ContainerBuilder $container): array
    {
        $ids = [];
        foreach ($container->getDefinitions() as $definition) {
            $decoratedService = $definition->getDecoratedService();
            if (null !== $decoratedService) {
                $ids[$decoratedService[0]] = true;
            }
        }

        return $ids;
    }

    /**
     * The service id a port resolves to (following alias chains), or null when nothing provides it. A decorated port
     * with no service behind it resolves to its own id (Symfony reports a missing inner service itself).
     *
     * @param array<string, true> $decorated
     *
     * @throws InvalidArgumentException When the port is aliased to a service that does not exist
     */
    private function target(ContainerBuilder $container, string $port, array $decorated): ?string
    {
        $id = $port;
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = (string) $container->getAlias($id);
        }

        if ($container->hasDefinition($id)) {
            return $id;
        }

        if ($id !== $port) {
            throw new InvalidArgumentException(\sprintf('The login attempt store port "%s" is aliased to "%s", which does not exist.', $port, $id));
        }

        return isset($decorated[$port]) ? $port : null;
    }

    /**
     * Fails the build early when the resolved service implements neither store port (a TypeError at the first login
     * otherwise). Services whose class cannot be resolved at compile time (factories, expressions) are left alone.
     */
    private function assertStore(ContainerBuilder $container, string $serviceId): void
    {
        $class = $container->getDefinition($serviceId)->getClass();
        if (null === $class) {
            return;
        }

        $class = $container->getParameterBag()->resolveValue($class);
        if (!\is_string($class) || !class_exists($class)) {
            return;
        }

        if (!is_a($class, LoginAttemptStoreInterface::class, true) && !is_a($class, LoginAttemptRepositoryInterface::class, true)) {
            throw new InvalidArgumentException(\sprintf('The login attempt store "%s" (%s) must implement %s.', $serviceId, $class, LoginAttemptStoreInterface::class));
        }
    }
}
