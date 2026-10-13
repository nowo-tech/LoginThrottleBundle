<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\RateLimiter;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
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
 * Usernames are normalized (trimmed and lower-cased) so case variants such as {@code Admin} and
 * {@code admin} share one bucket. A second, IP-only cap (default: 5 × {@code maxAttempts}, like
 * Symfony's {@code DefaultLoginRateLimiter}) blocks an IP that rotates usernames.
 * On successful login, {@see reset()} deletes matching {@see LoginAttempt} rows for that IP +
 * username only; the IP-wide counter keeps decaying with the timeout window.
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
     * Default multiplier applied to {@code maxAttempts} for the IP-only cap.
     */
    public const DEFAULT_IP_ATTEMPTS_MULTIPLIER = 5;

    /**
     * Maximum number of attempts per IP address, whatever the username.
     */
    private readonly int $maxAttemptsPerIp;

    /**
     * Constructor.
     *
     * @param LoginAttemptRepositoryInterface $repository       The login attempt repository
     * @param int                             $maxAttempts      Maximum number of attempts per IP + username
     * @param int                             $timeoutSeconds   Timeout period in seconds
     * @param int|null                        $maxAttemptsPerIp Maximum number of attempts per IP (null: 5 × maxAttempts)
     */
    public function __construct(
        private readonly LoginAttemptRepositoryInterface $repository,
        private readonly int $maxAttempts,
        private readonly int $timeoutSeconds,
        ?int $maxAttemptsPerIp = null
    ) {
        $this->maxAttemptsPerIp = max(1, $maxAttemptsPerIp ?? $maxAttempts * self::DEFAULT_IP_ATTEMPTS_MULTIPLIER);
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

        // Check if already blocked (IP + username first, then the IP-only cap)
        $isBlocked = $this->repository->isBlocked($ipAddress, $username, $this->maxAttempts, $this->timeoutSeconds);
        $isIpBlocked = !$isBlocked && $this->repository->countAttemptsByIp($ipAddress, $this->timeoutSeconds) >= $this->maxAttemptsPerIp;

        if ($isBlocked || $isIpBlocked) {
            $remaining = 0;
            $retryAfter = $this->calculateRetryAfter($ipAddress, $isBlocked ? $username : null);
            $accepted = false;
        } else {
            // Record the attempt
            $this->repository->recordAttempt($ipAddress, $username);

            // Count current attempts
            $count = $this->repository->countAttempts($ipAddress, $username, $this->timeoutSeconds);
            $ipCount = $this->repository->countAttemptsByIp($ipAddress, $this->timeoutSeconds);
            $remaining = max(0, min($this->maxAttempts - $count, $this->maxAttemptsPerIp - $ipCount));

            // Check if this attempt exceeded either limit
            if ($count >= $this->maxAttempts) {
                $retryAfter = $this->calculateRetryAfter($ipAddress, $username);
                $accepted = false;
            } elseif ($ipCount >= $this->maxAttemptsPerIp) {
                $retryAfter = $this->calculateRetryAfter($ipAddress, null);
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

        // @igor-ignore - Mutates attached request-scoped or value object; not worker singleton cache
        $this->repository->clearAttempts($ipAddress, $username);
    }

    /**
     * Extract username from request.
     *
     * Checks nested bags such as {@code login_form[_username]} (AuthKit) before flat keys.
     * The value is trimmed and lower-cased so case or whitespace variants share one bucket.
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

                $normalized = $this->normalizeUsername($value);

                if (null !== $normalized) {
                    return $normalized;
                }
            }
        }

        foreach (self::USERNAME_KEYS as $key) {
            $normalized = $this->normalizeUsername($request->request->get($key));

            if (null !== $normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Normalize a submitted username (trim + lower-case); non-string or blank values yield null.
     *
     * @param mixed $value Raw request value
     *
     * @return string|null The normalized username or null
     */
    private function normalizeUsername(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return '' === $normalized ? null : $normalized;
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
