<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\RateLimiter;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Throttle\ThrottleRules;
use Nowo\LoginThrottleBundle\Throttle\UsernameNormalizer;
use Psr\Clock\ClockInterface;
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
 * With {@code username_from_passport: true} (3.9), a request without those fields (json_login, custom
 * authenticators) is keyed on the passport identifier Symfony stores on the request, like form_login.
 * The rules live in {@see ThrottleRules}, shared with the status provider.
 * On successful login, {@see reset()} clears that IP + username's {@see LoginAttempt} rows (3.11.0: username set to NULL, so they keep counting for the IP); the username is the identifier Symfony authenticates ({@see UsernameNormalizer::forLimiter()}).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class DatabaseRateLimiter implements RequestRateLimiterInterface
{
    /**
     * Default multiplier applied to {@code maxAttempts} for the IP-only cap.
     */
    public const DEFAULT_IP_ATTEMPTS_MULTIPLIER = 5;

    /**
     * Maximum number of attempts per IP address, whatever the username.
     */
    private readonly int $maxAttemptsPerIp;

    private readonly ThrottleRules $rules;

    /**
     * Constructor.
     *
     * @param LoginAttemptRepositoryInterface $repository           The login attempt repository
     * @param int                             $maxAttempts          Maximum number of attempts per IP + username
     * @param int                             $timeoutSeconds       Timeout period in seconds
     * @param int|null                        $maxAttemptsPerIp     Maximum number of attempts per IP (null: 5 × maxAttempts)
     * @param ClockInterface|null             $clock                Time source (system time when null; REQ-DI-001)
     * @param bool                            $usernameFromPassport Fall back to the passport identifier when no POST field holds a username (3.9)
     */
    public function __construct(
        private readonly LoginAttemptRepositoryInterface $repository,
        private readonly int $maxAttempts,
        private readonly int $timeoutSeconds,
        ?int $maxAttemptsPerIp = null,
        private readonly ?ClockInterface $clock = null,
        private readonly bool $usernameFromPassport = false,
    ) {
        $this->rules = new ThrottleRules($repository, $maxAttempts, $timeoutSeconds, $maxAttemptsPerIp);
        $this->maxAttemptsPerIp = $this->rules->maxAttemptsPerIp();
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

        // Check if already blocked (IP + username first, then the IP-only cap). Without a username the bucket is the
        // IP itself, so only the cap applies (3.11.0): those are the rows the cap counts anyway.
        $isBlocked = null !== $username && $this->repository->isBlocked($ipAddress, $username, $this->maxAttempts, $this->timeoutSeconds);
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
            $limit = $this->rules->limitFor($username);
            $remaining = max(0, min($limit - $count, $this->maxAttemptsPerIp - $ipCount));

            // Check if this attempt exceeded either limit
            if (null !== $username && $count >= $limit) {
                $retryAfter = $this->calculateRetryAfter($ipAddress, $username);
                $accepted = false;
            } elseif ($ipCount >= $this->maxAttemptsPerIp) {
                $retryAfter = $this->calculateRetryAfter($ipAddress, null);
                $accepted = false;
            } else {
                // Not blocked, retry after is now (immediate retry allowed)
                $retryAfter = $this->clock?->now() ?? new \DateTimeImmutable();
                $accepted = true;
            }
        }

        // Ensure retryAfter is never null
        if (!$retryAfter instanceof \DateTimeImmutable) {
            $retryAfter = $this->clock?->now() ?? new \DateTimeImmutable();
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
     * Clears the stored attempts of this IP + username (they keep counting for the IP) so the next failure starts a fresh window.
     *
     * Without a username (KF-L-M1, 3.9) nothing is deleted: the IP-only bucket holds every attempt of the IP, the same
     * rows the per-IP cap counts, so clearing it on any successful login (e.g. the attacker's own account) would give
     * unlimited guesses. Those attempts decay with {@code timeout}, like Symfony's global login limiter.
     *
     * @param Request $request The request
     */
    public function reset(Request $request): void
    {
        $ipAddress = $request->getClientIp() ?? 'unknown';
        $username = $this->extractUsername($request);

        if (null === $username) {
            return;
        }

        // @igor-ignore - Mutates attached request-scoped or value object; not worker singleton cache
        $this->repository->clearAttempts($ipAddress, $username);
    }

    /**
     * Extract the normalised username from the request ({@see UsernameNormalizer}).
     *
     * @param Request $request The request
     *
     * @return string|null The username or null
     */
    private function extractUsername(Request $request): ?string
    {
        return UsernameNormalizer::forLimiter($request, $this->usernameFromPassport);
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
        return $this->rules->retryAfter($ipAddress, $username);
    }
}
