<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Event;

/**
 * Dispatched by the database limiter after a successful login cleared the IP + username bucket (3.14, REQ-ARCH-001
 * rule 4).
 *
 * Scalars only. Dispatched after the store call returned (an autocommitted DBAL statement in the bundle's store).
 * Not dispatched for a login without a usable username: that bucket is never cleared (KF-L-M1).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class LoginAttemptsClearedEvent
{
    /**
     * @param string      $ipAddress    Client IP ({@code unknown} when Symfony has none)
     * @param string      $username     Normalised username whose attempts were cleared
     * @param int         $cleared      Number of rows the store reported as affected
     * @param string|null $firewallName Firewall that handled the request, when Symfony exposes it
     */
    public function __construct(
        public string $ipAddress,
        public string $username,
        public int $cleared,
        public ?string $firewallName = null,
    ) {
    }
}
