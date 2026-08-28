<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\RateLimiter;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Database-backed rate limiter for login throttling.
 *
 * This rate limiter stores login attempts in the database instead of using cache.
 * It implements Symfony's RequestRateLimiterInterface to work with login_throttling.
 *
 * Username extraction supports flat form fields ({@code _username}, {@code username},
 * {@code email}) and AuthKit-style nested bags ({@code login_form[_username]}, etc.).
 * On successful login, {@see reset()} deletes matching {@see \Nowo\LoginThrottleBundle\Entity\LoginAttempt} rows.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class DatabaseRateLimiter implements RequestRateLimiterInterface
{
    /**
     * Nested request bags checked before flat keys (AuthKit / FormType names).
     *
     * @var list<string>
     */
    private const NESTED_FORM_BAGS = ['login_form'];

    /**
     * Username parameter keys (nested bag first, then root request).
     *
     * @var list<string>
     */
    private const USERNAME_KEYS = ['_username', 'username', 'email'];

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
        if (!$retryAfter instanceof \DateTimeImmutable) {
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
     * Reset the rate limiter for the given request (successful login).
     *
     * Deletes stored attempts for this IP + username so the next failure starts a fresh window.
     *
     * @param Request $request The request
     */
    public function reset(Request $request): void
    {
        $ipAddress = $request->getClientIp() ?? 'unknown';
        $username = $this->extractUsername($request);

        $this->repository->clearAttempts($ipAddress, $username);
    }

    /**
     * Extract username from request.
     *
     * Checks nested bags such as {@code login_form[_username]} (AuthKit) before flat keys.
     *
     * @param Request $request The request
     *
     * @return string|null The username or null
     */
    private function extractUsername(Request $request): ?string
    {
        foreach (self::NESTED_FORM_BAGS as $bag) {
            $nested = $request->request->all($bag);

            foreach (self::USERNAME_KEYS as $key) {
                $value = $nested[$key] ?? null;

                if (\is_string($value) && '' !== $value) {
                    return $value;
                }
            }
        }

        foreach (self::USERNAME_KEYS as $key) {
            $value = $request->request->get($key);

            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
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

        if ([] === $attempts) {
            return null;
        }

        // getAttempts orders by DESC (newest first); the last element is the oldest
        $oldestAttempt = $attempts[array_key_last($attempts)];
        $retryAfter = $oldestAttempt->getCreatedAt()->modify(\sprintf('+%d seconds', $this->timeoutSeconds));

        return $retryAfter instanceof \DateTimeImmutable ? $retryAfter : null;
    }
}
