<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\RateLimiter;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;

/**
 * Factory for creating DatabaseRateLimiter instances.
 *
 * This factory allows the rate limiter to be configured as a Symfony service
 * and used with the login_throttling feature.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class DatabaseRateLimiterFactory
{
    /**
     * Constructor.
     *
     * @param LoginAttemptRepositoryInterface $repository                 The login attempt repository
     * @param int                             $maxAttempts                Maximum number of attempts per IP + username
     * @param int                             $timeoutSeconds             Timeout period in seconds
     * @param int|null                        $maxAttemptsPerIp           Maximum number of attempts per IP (null: 5 × maxAttempts)
     * @param bool                            $usernameFromPassport       Fall back to the passport identifier when no POST field holds a username (3.9, option {@code username_from_passport})
     * @param int|null                        $maxAttemptsWithoutUsername Maximum number of attempts per IP without a usable username (3.13.0, option {@code max_count_attempts_without_username}; null: the per-IP cap)
     */
    public function __construct(
        private readonly LoginAttemptRepositoryInterface $repository,
        private readonly int $maxAttempts,
        private readonly int $timeoutSeconds,
        private readonly ?int $maxAttemptsPerIp = null,
        private readonly bool $usernameFromPassport = false,
        private readonly ?int $maxAttemptsWithoutUsername = null,
    ) {
    }

    /**
     * Create a DatabaseRateLimiter instance.
     *
     * @return DatabaseRateLimiter The rate limiter instance
     */
    public function create(): DatabaseRateLimiter
    {
        return new DatabaseRateLimiter(
            $this->repository,
            $this->maxAttempts,
            $this->timeoutSeconds,
            $this->maxAttemptsPerIp,
            null,
            $this->usernameFromPassport,
            $this->maxAttemptsWithoutUsername,
        );
    }
}
