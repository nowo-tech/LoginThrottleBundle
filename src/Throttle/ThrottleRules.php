<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Throttle;

use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptViewRepositoryInterface;
use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProvider;

/**
 * The database limiter's rules in one place (3.9): limits, the per-IP cap and the retry time.
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

    /**
     * @param LoginAttemptRepositoryInterface $repository       The login attempt store
     * @param int                             $maxAttempts      Maximum number of attempts per IP + username
     * @param int                             $timeoutSeconds   Timeout window in seconds
     * @param int|null                        $maxAttemptsPerIp Maximum number of attempts per IP (null: 5 × maxAttempts)
     */
    public function __construct(
        private LoginAttemptRepositoryInterface $repository,
        private int $maxAttempts,
        private int $timeoutSeconds,
        ?int $maxAttemptsPerIp = null,
    ) {
        $this->maxAttemptsPerIp = max(1, $maxAttemptsPerIp ?? $maxAttempts * self::DEFAULT_IP_ATTEMPTS_MULTIPLIER);
    }

    /**
     * Maximum number of attempts per IP address, whatever the username.
     */
    public function maxAttemptsPerIp(): int
    {
        return $this->maxAttemptsPerIp;
    }

    /**
     * Returns the state of the IP + username bucket without recording anything.
     *
     * Blocked exactly when the limiter's next {@code consume()} refuses the attempt before recording it.
     *
     * Without a username the bucket is the IP itself, so its limit is {@see maxAttemptsPerIp()} (3.10.2): those rows
     * are the ones the per-IP cap counts, and `max_count_attempts` would make a shared IP (office NAT) refuse requests
     * without a usable username after a handful of other people's failures.
     *
     * @param string      $ipAddress Client IP ("unknown" when absent, as the limiter does)
     * @param string|null $username  Normalised username ({@see UsernameNormalizer}), null for the IP-only bucket
     */
    public function status(string $ipAddress, ?string $username): ThrottleStatus
    {
        $limit = $this->limitFor($username);
        $count = $this->repository->countAttempts($ipAddress, $username, $this->timeoutSeconds);
        $ipCount = $this->repository->countAttemptsByIp($ipAddress, $this->timeoutSeconds);
        $userBlocked = null !== $username && $this->repository->isBlocked($ipAddress, $username, $this->maxAttempts, $this->timeoutSeconds);
        $blocked = $userBlocked || $ipCount >= $this->maxAttemptsPerIp;

        return new ThrottleStatus(
            $count,
            $limit,
            $blocked ? 0 : max(0, min($limit - $count, $this->maxAttemptsPerIp - $ipCount)),
            $blocked,
            $blocked ? $this->retryAfter($ipAddress, $userBlocked ? $username : null) : null,
            null !== $username ? ThrottleStatus::TRACKING_USERNAME : ThrottleStatus::TRACKING_IP,
            $ipCount,
            $this->maxAttemptsPerIp,
        );
    }

    /**
     * The limit of the IP + username bucket: `max_count_attempts` with a username, the per-IP cap without one.
     */
    public function limitFor(?string $username): int
    {
        return null === $username ? $this->maxAttemptsPerIp : $this->maxAttempts;
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

        if (null === $createdAt) {
            return null;
        }

        // Built from the timestamp instead of modify() (static|false before PHP 8.3) so PHPStan reports the
        // same result on PHP 8.2 to 8.5; the attempt's timezone is kept.
        return (new \DateTimeImmutable('@' . ($createdAt->getTimestamp() + $this->timeoutSeconds)))
            ->setTimezone($createdAt->getTimezone());
    }
}
