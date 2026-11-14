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
 * the internal alias {@see self::STORE_SERVICE_ID}. It points to, in order:
 *
 * 1. the host's own {@see LoginAttemptStoreInterface} service (entity-free port; wins when both are aliased);
 * 2. the host's own {@see LoginAttemptRepositoryInterface} service (deprecated port, kept for existing hosts);
 * 3. the bundle's {@see LoginAttemptRepository} (the default, unchanged).
 *
 * Without any store (doctrine.register_mapping off and no host alias) the alias is removed, the optional consumers get
 * null as before, and a firewall with {@code storage: database} fails the build with the same message as before 3.14.
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
        $store = $this->target($container, LoginAttemptStoreInterface::class);
        $legacy = $this->target($container, LoginAttemptRepositoryInterface::class);

        $target = match (true) {
            null !== $store && LoginAttemptRepository::class !== $store => $store,
            null !== $legacy && LoginAttemptRepository::class !== $legacy => $legacy,
            default => $store ?? $legacy,
        };

        if (null === $target) {
            if ($container->hasAlias(self::STORE_SERVICE_ID)) {
                $container->removeAlias(self::STORE_SERVICE_ID);
            }

            if ([] !== $container->findTaggedServiceIds(self::DATABASE_LIMITER_TAG)) {
                throw new InvalidArgumentException(\sprintf('"nowo_login_throttle.doctrine.register_mapping: false" requires storage "cache" on every firewall: storage "database" needs the LoginAttempt entity (login_attempts table). Use "auto" or true, or alias %s to your own store.', LoginAttemptStoreInterface::class));
            }

            return;
        }

        $this->assertStore($container, $target);
        $container->setAlias(self::STORE_SERVICE_ID, $target)->setPublic(false);
    }

    /**
     * The service id an interface resolves to (following alias chains), or null when nothing provides it.
     */
    private function target(ContainerBuilder $container, string $id): ?string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = (string) $container->getAlias($id);
        }

        return $container->hasDefinition($id) ? $id : null;
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
