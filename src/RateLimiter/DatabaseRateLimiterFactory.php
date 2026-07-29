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
     * @param LoginAttemptRepositoryInterface $repository     The login attempt repository
     * @param int                             $maxAttempts    Maximum number of attempts
     * @param int                             $timeoutSeconds Timeout period in seconds
     */
    public function __construct(
        private readonly LoginAttemptRepositoryInterface $repository,
        private readonly int $maxAttempts,
        private readonly int $timeoutSeconds
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
            $this->timeoutSeconds
        );
    }
}
