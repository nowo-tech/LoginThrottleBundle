<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Throttle;

use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptViewRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptWithoutUsernameReaderInterface;
use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProvider;

/**
 * The database limiter's rules in one place (3.9): limits, the per-IP cap, the bound without a username (3.13.0) and
 * the retry time.
 *
 * {@see DatabaseRateLimiter} uses it to refuse attempts and
 * {@see LoginThrottleStatusProvider} to report them, so what a login page shows is
 * what the limiter enforces. {@see self::status()} only reads; it never records an attempt.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class ThrottleRules
{
    /**
     * Default multiplier applied to {@code maxAttempts} for the IP-only cap.
     */
    public const DEFAULT_IP_ATTEMPTS_MULTIPLIER = 5;

    private int $maxAttemptsPerIp;

    private int $maxAttemptsWithoutUsername;

    /**
     * @param LoginAttemptRepositoryInterface $repository                 The login attempt store
     * @param int                             $maxAttempts                Maximum number of attempts per IP + username
     * @param int                             $timeoutSeconds             Timeout window in seconds
     * @param int|null                        $maxAttemptsPerIp           Maximum number of attempts per IP (null: 5 × maxAttempts)
     * @param int|null                        $maxAttemptsWithoutUsername Maximum number of attempts per IP without a usable username (3.13.0; null: the per-IP cap). Clamped to 1 .. the per-IP cap, which applies on top anyway
     */
    public function __construct(
        private LoginAttemptRepositoryInterface $repository,
        private int $maxAttempts,
        private int $timeoutSeconds,
        ?int $maxAttemptsPerIp = null,
        ?int $maxAttemptsWithoutUsername = null,
    ) {
        $this->maxAttemptsPerIp = max(1, $maxAttemptsPerIp ?? $maxAttempts * self::DEFAULT_IP_ATTEMPTS_MULTIPLIER);
        $this->maxAttemptsWithoutUsername = min($this->maxAttemptsPerIp, max(1, $maxAttemptsWithoutUsername ?? $this->maxAttemptsPerIp));
    }

    /**
     * Maximum number of attempts per IP address, whatever the username.
     */
    public function maxAttemptsPerIp(): int
    {
        return $this->maxAttemptsPerIp;
    }

    /**
     * Maximum number of attempts per IP address without a usable username (3.13.0); the per-IP cap by default.
     */
    public function maxAttemptsWithoutUsername(): int
    {
        return $this->maxAttemptsWithoutUsername;
    }

    /**
     * Whether the bound without a username is tighter than the per-IP cap (3.13.0). Only then are the IP's username-less
     * rows counted separately: an equal bound is the per-IP cap, which already counts them.
     */
    public function boundsAttemptsWithoutUsername(): bool
    {
        return $this->maxAttemptsWithoutUsername < $this->maxAttemptsPerIp;
    }

    /**
     * Returns the state of the IP + username bucket without recording anything.
     *
     * Blocked exactly when the limiter's next {@code consume()} refuses the attempt before recording it.
     *
     * Without a username the bucket is the IP itself, so its limit is {@see maxAttemptsPerIp()} (3.11.0): those rows
     * are the ones the per-IP cap counts, and `max_count_attempts` would make a shared IP (office NAT) refuse requests
     * without a usable username after a handful of other people's failures. With a tighter
     * `max_count_attempts_without_username` (3.13.0) the bucket is the IP's rows without a username, bounded by
     * {@see maxAttemptsWithoutUsername()}, and the per-IP cap still applies on top.
     *
     * @param string      $ipAddress Client IP ("unknown" when absent, as the limiter does)
     * @param string|null $username  Normalised username ({@see UsernameNormalizer}), null for the IP-only bucket
     */
    public function status(string $ipAddress, ?string $username): ThrottleStatus
    {
        $limit = $this->limitFor($username);
        $count = $this->countBucket($ipAddress, $username);
        $ipCount = $this->repository->countAttemptsByIp($ipAddress, $this->timeoutSeconds);
        $userBlocked = null !== $username && $this->repository->isBlocked($ipAddress, $username, $this->maxAttempts, $this->timeoutSeconds);
        $ipBlocked = $ipCount >= $this->maxAttemptsPerIp;
        // only a tighter bound refuses on this count (3.13.1, as the limiter does); otherwise the per-IP cap decides
        $withoutUsernameBlocked = null === $username && $this->boundsAttemptsWithoutUsername() && $count >= $limit;
        $blocked = $userBlocked || $ipBlocked || $withoutUsernameBlocked;

        $retryAfter = null;
        if ($userBlocked || $ipBlocked) {
            $retryAfter = $this->retryAfter($ipAddress, $userBlocked ? $username : null);
        } elseif ($blocked) {
            $retryAfter = $this->retryAfterWithoutUsername($ipAddress);
        }

        return new ThrottleStatus(
            $count,
            $limit,
            $blocked ? 0 : max(0, min($limit - $count, $this->maxAttemptsPerIp - $ipCount)),
            $blocked,
            $retryAfter,
            null !== $username ? ThrottleStatus::TRACKING_USERNAME : ThrottleStatus::TRACKING_IP,
            $ipCount,
            $this->maxAttemptsPerIp,
        );
    }

    /**
     * The limit of the IP + username bucket: `max_count_attempts` with a username, the bound without a username
     * (3.13.0; the per-IP cap by default) without one.
     */
    public function limitFor(?string $username): int
    {
        return null === $username ? $this->maxAttemptsWithoutUsername : $this->maxAttempts;
    }

    /**
     * Attempts in the bucket of this IP + username: the username's (folded) attempts with a username; without one,
     * every attempt of the IP, or only those without a username when {@see boundsAttemptsWithoutUsername()} (3.13.0).
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Normalised username, null for the IP-only bucket
     */
    public function countBucket(string $ipAddress, ?string $username): int
    {
        if (null !== $username || !$this->boundsAttemptsWithoutUsername()) {
            return $this->repository->countAttempts($ipAddress, $username, $this->timeoutSeconds);
        }

        if ($this->repository instanceof LoginAttemptWithoutUsernameReaderInterface) {
            return $this->repository->countAttemptsWithoutUsername($ipAddress, $this->timeoutSeconds);
        }

        return \count($this->attemptTimesWithoutUsername($ipAddress));
    }

    /**
     * When the oldest attempt of this IP without a username leaves the timeout window (3.13.0).
     *
     * @param string $ipAddress IP address
     *
     * @return \DateTimeImmutable|null Retry time, or null without such attempts in the window
     */
    public function retryAfterWithoutUsername(string $ipAddress): ?\DateTimeImmutable
    {
        if ($this->repository instanceof LoginAttemptWithoutUsernameReaderInterface) {
            return $this->after($this->repository->findOldestAttemptWithoutUsernameAt($ipAddress, $this->timeoutSeconds));
        }

        $times = $this->attemptTimesWithoutUsername($ipAddress);

        return $this->after([] === $times ? null : min($times));
    }

    /**
     * When the oldest attempt of the bucket leaves the timeout window.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (null: every attempt of the IP)
     *
     * @return \DateTimeImmutable|null Retry time, or null without attempts in the window
     */
    public function retryAfter(string $ipAddress, ?string $username): ?\DateTimeImmutable
    {
        if ($this->repository instanceof LoginAttemptViewRepositoryInterface) {
            $createdAt = $this->repository->findOldestAttemptAt($ipAddress, $username, $this->timeoutSeconds);
        } else {
            // getAttempts orders by DESC (newest first); the last element is the oldest
            $attempts = $this->repository->getAttempts($ipAddress, $username, $this->timeoutSeconds);
            $createdAt = [] === $attempts ? null : $attempts[array_key_last($attempts)]->getCreatedAt();
        }

        return $this->after($createdAt);
    }

    /**
     * The end of the timeout window that starts at $createdAt.
     */
    private function after(?\DateTimeImmutable $createdAt): ?\DateTimeImmutable
    {
        if (!$createdAt instanceof \DateTimeImmutable) {
            return null;
        }

        // Built from the timestamp instead of modify() (static|false before PHP 8.3) so PHPStan reports the
        // same result on PHP 8.2 to 8.5; the attempt's timezone is kept.
        return (new \DateTimeImmutable('@' . ($createdAt->getTimestamp() + $this->timeoutSeconds)))
            ->setTimezone($createdAt->getTimezone());
    }

    /**
     * Times of this IP's attempts without a username, for a store without
     * {@see LoginAttemptWithoutUsernameReaderInterface}: its IP rows (entity-free when it can), filtered here. A host
     * store that writes `''` for "no username" is counted too (3.13.1); the bundle's own stores write null.
     *
     * @return list<\DateTimeImmutable>
     */
    private function attemptTimesWithoutUsername(string $ipAddress): array
    {
        $times = [];
        if ($this->repository instanceof LoginAttemptViewRepositoryInterface) {
            foreach ($this->repository->getAttemptViews($ipAddress, null, $this->timeoutSeconds) as $view) {
                if ($this->isWithoutUsername($view->username) && $view->ipAddress === $ipAddress) {
                    $times[] = $view->createdAt;
                }
            }

            return $times;
        }

        foreach ($this->repository->getAttempts($ipAddress, null, $this->timeoutSeconds) as $attempt) {
            if ($this->isWithoutUsername($attempt->getUsername()) && $attempt->getIpAddress() === $ipAddress) {
                $times[] = $attempt->getCreatedAt();
            }
        }

        return $times;
    }

    /**
     * Whether a stored username means "no username": null, or `''` from a host store (3.13.1).
     */
    private function isWithoutUsername(?string $username): bool
    {
        return null === $username || '' === $username;
    }
}
