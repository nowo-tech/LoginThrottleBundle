<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\RateLimiter;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Event\LoginAttemptsClearedEvent;
use Nowo\LoginThrottleBundle\Event\LoginThrottledEvent;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRecorderInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Throttle\ThrottleRules;
use Nowo\LoginThrottleBundle\Throttle\UsernameNormalizer;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
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
 * Symfony's {@code DefaultLoginRateLimiter}) blocks an IP that rotates usernames. Requests without a usable username
 * are bounded by that cap, or by the tighter {@code max_count_attempts_without_username} when set (3.13.0), which
 * counts only the IP's attempts without a username; the cap still applies on top.
 * With {@code username_from_passport: true} (3.9), a request without those fields (json_login, custom
 * authenticators) is keyed on the passport identifier Symfony stores on the request, like form_login.
 * The rules live in {@see ThrottleRules}, shared with the status provider.
 * Attempts are recorded through {@see LoginAttemptRecorderInterface} when the store implements it (3.12), so no
 * Doctrine entity crosses the port; otherwise through the deprecated {@see LoginAttemptRepositoryInterface::recordAttempt()}.
 * Since 3.14 the store may be the entity-free {@see LoginAttemptStoreInterface} (or the deprecated
 * {@see LoginAttemptRepositoryInterface}), and when an event dispatcher is given a refused attempt dispatches
 * {@see LoginThrottledEvent} and a cleared bucket {@see LoginAttemptsClearedEvent} (scalars only, after the write).
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
     * @param LoginAttemptRepositoryInterface|LoginAttemptStoreInterface $repository                 The login attempt store ({@see LoginAttemptStoreInterface} since 3.14)
     * @param int                                                        $maxAttempts                Maximum number of attempts per IP + username
     * @param int                                                        $timeoutSeconds             Timeout period in seconds
     * @param int|null                                                   $maxAttemptsPerIp           Maximum number of attempts per IP (null: 5 × maxAttempts)
     * @param ClockInterface|null                                        $clock                      Time source (system time when null; REQ-DI-001)
     * @param bool                                                       $usernameFromPassport       Fall back to the passport identifier when no POST field holds a username (3.9)
     * @param int|null                                                   $maxAttemptsWithoutUsername Maximum number of attempts per IP without a usable username (3.13.0; null: the per-IP cap)
     * @param EventDispatcherInterface|null                              $eventDispatcher            Dispatches {@see LoginThrottledEvent} / {@see LoginAttemptsClearedEvent} (3.14; none when null)
     */
    public function __construct(
        private readonly LoginAttemptRepositoryInterface|LoginAttemptStoreInterface $repository,
        private readonly int $maxAttempts,
        private readonly int $timeoutSeconds,
        ?int $maxAttemptsPerIp = null,
        private readonly ?ClockInterface $clock = null,
        private readonly bool $usernameFromPassport = false,
        ?int $maxAttemptsWithoutUsername = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->rules = new ThrottleRules($repository, $maxAttempts, $timeoutSeconds, $maxAttemptsPerIp, $maxAttemptsWithoutUsername);
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
        // the limit this request is judged by, which {@see RateLimit::getLimit()} must report (3.11.0)
        $limit = $this->rules->limitFor($username);

        // Check if already blocked (IP + username first, then the IP-only cap). Without a username the bucket is the
        // IP itself, so only the cap applies (3.11.0): those are the rows the cap counts anyway. A tighter bound
        // without a username (3.13.0) counts the IP's rows without a username, below the cap.
        $boundsWithoutUsername = null === $username && $this->rules->boundsAttemptsWithoutUsername();
        $isBlocked = null !== $username && $this->repository->isBlocked($ipAddress, $username, $this->maxAttempts, $this->timeoutSeconds);
        $isIpBlocked = !$isBlocked && $this->repository->countAttemptsByIp($ipAddress, $this->timeoutSeconds) >= $this->maxAttemptsPerIp;
        $isBlockedWithoutUsername = $boundsWithoutUsername && !$isIpBlocked && $this->rules->countBucket($ipAddress, null) >= $limit;

        // which limit refused the attempt (3.14, for LoginThrottledEvent); null while accepted
        $scope = null;
        $lockoutStarted = false;

        if ($isBlocked || $isIpBlocked || $isBlockedWithoutUsername) {
            $remaining = 0;
            $retryAfter = $isBlockedWithoutUsername
                ? $this->rules->retryAfterWithoutUsername($ipAddress)
                : $this->calculateRetryAfter($ipAddress, $isBlocked ? $username : null);
            $accepted = false;
            $scope = match (true) {
                $isBlocked => LoginThrottledEvent::SCOPE_USERNAME,
                $isIpBlocked => LoginThrottledEvent::SCOPE_IP,
                default => LoginThrottledEvent::SCOPE_WITHOUT_USERNAME,
            };
        } else {
            // Record the attempt
            $this->record($ipAddress, $username);

            // Count current attempts
            $count = $this->rules->countBucket($ipAddress, $username);
            $ipCount = $this->repository->countAttemptsByIp($ipAddress, $this->timeoutSeconds);
            $remaining = max(0, min($limit - $count, $this->maxAttemptsPerIp - $ipCount));

            // Check if this attempt exceeded either limit
            if (null !== $username && $count >= $limit) {
                $retryAfter = $this->calculateRetryAfter($ipAddress, $username);
                $accepted = false;
                $scope = LoginThrottledEvent::SCOPE_USERNAME;
            } elseif ($ipCount >= $this->maxAttemptsPerIp) {
                $retryAfter = $this->calculateRetryAfter($ipAddress, null);
                $accepted = false;
                $scope = LoginThrottledEvent::SCOPE_IP;
            } elseif ($boundsWithoutUsername && $count >= $limit) {
                $retryAfter = $this->rules->retryAfterWithoutUsername($ipAddress);
                $accepted = false;
                $scope = LoginThrottledEvent::SCOPE_WITHOUT_USERNAME;
            } else {
                // Not blocked, retry after is now (immediate retry allowed)
                $retryAfter = $this->clock?->now() ?? new \DateTimeImmutable();
                $accepted = true;
            }
            $lockoutStarted = null !== $scope;
        }

        // Ensure retryAfter is never null
        if (!$retryAfter instanceof \DateTimeImmutable) {
            $retryAfter = $this->clock?->now() ?? new \DateTimeImmutable();
        }

        if (null !== $scope && $this->eventDispatcher instanceof EventDispatcherInterface) {
            // after the attempt was written (autocommitted DBAL insert in the bundle's store), scalars only
            $this->eventDispatcher->dispatch(new LoginThrottledEvent(
                $ipAddress,
                $username,
                $scope,
                LoginThrottledEvent::SCOPE_IP === $scope ? $this->maxAttemptsPerIp : $limit,
                $retryAfter,
                $lockoutStarted,
                $this->firewallName($request),
            ));
        }

        return new RateLimit(
            $remaining,
            $retryAfter,
            $accepted,
            $limit
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
        $cleared = $this->repository->clearAttempts($ipAddress, $username);

        $this->eventDispatcher?->dispatch(new LoginAttemptsClearedEvent($ipAddress, $username, $cleared, $this->firewallName($request)));
    }

    /**
     * The firewall Symfony's firewall map stored on the request ({@code _firewall_context}, e.g.
     * {@code security.firewall.map.context.main} → {@code main}); null outside a firewall.
     *
     * @param Request $request The request
     */
    private function firewallName(Request $request): ?string
    {
        $context = $request->attributes->get('_firewall_context');
        if (!\is_string($context) || '' === $context) {
            return null;
        }

        $prefix = 'security.firewall.map.context.';

        return str_starts_with($context, $prefix) ? substr($context, \strlen($prefix)) : $context;
    }

    /**
     * Records one failed attempt.
     *
     * Through {@see LoginAttemptRecorderInterface::recordAttemptView()} when the store implements it (3.12), so the
     * limiter handles no Doctrine entity; otherwise through the deprecated
     * {@see LoginAttemptRepositoryInterface::recordAttempt()}. Neither return value is used: the counts below are
     * read back from the store.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Normalised username, null for the IP-only bucket
     */
    private function record(string $ipAddress, ?string $username): void
    {
        if ($this->repository instanceof LoginAttemptRecorderInterface) {
            $this->repository->recordAttemptView($ipAddress, $username);

            return;
        }

        $this->repository->recordAttempt($ipAddress, $username);
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
