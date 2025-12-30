<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\RateLimiter;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Database-backed rate limiter for login throttling.
 *
 * This rate limiter stores login attempts in the database instead of using cache.
 * It implements Symfony's RequestRateLimiterInterface to work with login_throttling.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
class DatabaseRateLimiter implements RequestRateLimiterInterface
{
    /**
     * Constructor.
     *
     * @param LoginAttemptRepository $repository         The login attempt repository
     * @param int                    $maxAttempts        Maximum number of attempts
     * @param int                    $timeoutSeconds     Timeout period in seconds
     * @param int                    $watchPeriodSeconds Watch period in seconds
     */
    public function __construct(
        private readonly LoginAttemptRepository $repository,
        private readonly int $maxAttempts,
        private readonly int $timeoutSeconds,
        private readonly int $watchPeriodSeconds
    ) {
    }

    /**
     * Consume a token for the given request.
     *
     * @param Request $request The request
     *
     * @return RateLimit The rate limit state
     */
    public function consume(Request $request): RateLimit
    {
        $ipAddress = $request->getClientIp() ?? 'unknown';
        $username = $this->extractUsername($request);

        // Check if already blocked
        $isBlocked = $this->repository->isBlocked($ipAddress, $username, $this->maxAttempts, $this->timeoutSeconds);

        if ($isBlocked) {
            $remaining = 0;
            $retryAfter = $this->calculateRetryAfter($ipAddress, $username);
            $accepted = false;
        } else {
            // Record the attempt
            $this->repository->recordAttempt($ipAddress, $username);

            // Count current attempts
            $count = $this->repository->countAttempts($ipAddress, $username, $this->timeoutSeconds);
            $remaining = max(0, $this->maxAttempts - $count);
            
            // Check if this attempt exceeded the limit
            if ($count >= $this->maxAttempts) {
                $retryAfter = $this->calculateRetryAfter($ipAddress, $username);
                $accepted = false;
            } else {
                // Not blocked, retry after is now (immediate retry allowed)
                $retryAfter = new \DateTimeImmutable();
                $accepted = true;
            }
        }

        // Ensure retryAfter is never null
        if (null === $retryAfter) {
            $retryAfter = new \DateTimeImmutable();
        }

        return new RateLimit(
            $remaining,
            $retryAfter,
            $accepted,
            $this->maxAttempts
        );
    }

    /**
     * Reset the rate limiter for the given request.
     *
     * @param Request $request The request
     *
     * @return void
     */
    public function reset(Request $request): void
    {
        $ipAddress = $request->getClientIp() ?? 'unknown';
        $username = $this->extractUsername($request);

        // Clean up old attempts for this IP/username
        // Note: We don't delete all attempts, just let them expire naturally
        // This allows for better auditing and analysis
    }

    /**
     * Extract username from request.
     *
     * @param Request $request The request
     *
     * @return string|null The username or null
     */
    private function extractUsername(Request $request): ?string
    {
        // Try to get username from request parameters (login form)
        $username = $request->request->get('_username')
            ?? $request->request->get('username')
            ?? $request->request->get('email');

        return $username ? (string) $username : null;
    }

    /**
     * Calculate retry after timestamp.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username
     *
     * @return \DateTimeImmutable|null Retry after timestamp
     */
    private function calculateRetryAfter(string $ipAddress, ?string $username): ?\DateTimeImmutable
    {
        $attempts = $this->repository->getAttempts($ipAddress, $username, $this->timeoutSeconds);

        if (empty($attempts)) {
            return null;
        }

        // Get the oldest attempt within the timeout period (first in array, sorted DESC means last is oldest)
        // Since getAttempts orders by DESC (newest first), the last element is the oldest
        $oldestAttempt = end($attempts);
        if (!$oldestAttempt) {
            return null;
        }

        // Calculate when the timeout period expires
        $retryAfter = $oldestAttempt->getCreatedAt()->modify(sprintf('+%d seconds', $this->timeoutSeconds));

        return $retryAfter;
    }
}
