<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Event;

/**
 * Dispatched by the database limiter when it refuses a login attempt (3.14, REQ-ARCH-001 rule 4).
 *
 * Carries scalars and an immutable date only (no request, no entity, no Symfony type), so a DDD host can map it to its
 * own command (audit, notification). One event per refused attempt: {@see $lockoutStarted} is true for the attempt
 * that reached the limit (it was recorded) and false for the following ones refused while the lock lasts (not
 * recorded). Dispatched after the attempt was written: the bundle's store writes with an autocommitted DBAL insert,
 * never through the host's EntityManager.
 *
 * Only {@code storage: database} dispatches it; with {@code storage: cache} Symfony's own limiter decides and the
 * bundle sees nothing (listen to Symfony's {@code LoginFailureEvent} there).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class LoginThrottledEvent
{
    /**
     * The IP + username bucket reached {@code max_count_attempts}.
     */
    public const SCOPE_USERNAME = 'username';

    /**
     * The IP reached its cap ({@code max_count_attempts_per_ip}), whatever the username.
     */
    public const SCOPE_IP = 'ip';

    /**
     * The IP's attempts without a usable username reached {@code max_count_attempts_without_username} (3.13.0).
     */
    public const SCOPE_WITHOUT_USERNAME = 'without_username';

    /**
     * @param string             $ipAddress      Client IP ({@code unknown} when Symfony has none)
     * @param string|null        $username       Normalised username the attempt was keyed on, null without one
     * @param string             $scope          Which limit refused it: one of the SCOPE_* constants
     * @param int                $limit          The limit of that scope
     * @param \DateTimeImmutable $retryAfter     When the visitor may try again
     * @param bool               $lockoutStarted True when this attempt reached the limit, false when it was refused while already locked
     * @param string|null        $firewallName   Firewall that handled the request, when Symfony exposes it
     */
    public function __construct(
        public string $ipAddress,
        public ?string $username,
        public string $scope,
        public int $limit,
        public \DateTimeImmutable $retryAfter,
        public bool $lockoutStarted,
        public ?string $firewallName = null,
    ) {
    }
}
