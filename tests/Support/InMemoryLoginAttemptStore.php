<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;

/**
 * Host-style attempt store without Doctrine (same filters as LoginAttemptRepository), on a settable clock.
 *
 * Implements only the original port, so callers must fall back to getAttempts() for the retry time.
 */
class InMemoryLoginAttemptStore implements LoginAttemptRepositoryInterface
{
    /** @var list<array{ip: string, username: ?string, at: \DateTimeImmutable}> */
    protected array $rows = [];

    public \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-11-10T10:00:00+00:00');
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }

    public function countAttempts(string $ipAddress, ?string $username, int $seconds): int
    {
        return \count($this->matching($ipAddress, $username, $seconds));
    }

    public function countAttemptsByIp(string $ipAddress, int $seconds): int
    {
        return \count($this->matching($ipAddress, null, $seconds));
    }

    public function countAttemptsByUsername(string $username, int $seconds): int
    {
        return \count($this->matching('', $username, $seconds));
    }

    public function isBlocked(string $ipAddress, ?string $username, int $maxAttempts, int $timeoutSeconds): bool
    {
        return $this->countAttempts($ipAddress, $username, $timeoutSeconds) >= $maxAttempts;
    }

    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
    {
        $this->rows[] = ['ip' => $ipAddress, 'username' => $username, 'at' => $this->now];

        return $this->entity($ipAddress, $username, $this->now);
    }

    public function clearAttempts(string $ipAddress, ?string $username): int
    {
        $before = \count($this->rows);
        $key = '' === $username ? null : $username;
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['ip'] !== $ipAddress || $row['username'] !== $key,
        ));

        return $before - \count($this->rows);
    }

    public function cleanup(int $watchPeriodSeconds): int
    {
        return 0;
    }

    public function countOlderThan(int $watchPeriodSeconds): int
    {
        return 0;
    }

    public function getAttempts(string $ipAddress, ?string $username, int $seconds): array
    {
        return array_map(
            fn (array $row): LoginAttempt => $this->entity($row['ip'], $row['username'], $row['at']),
            $this->matching($ipAddress, $username, $seconds),
        );
    }

    /**
     * Newest first, like the Doctrine repository.
     *
     * @return list<array{ip: string, username: ?string, at: \DateTimeImmutable}>
     */
    protected function matching(string $ipAddress, ?string $username, int $seconds): array
    {
        $since = $this->now->modify('-' . $seconds . ' seconds');
        $rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['at'] >= $since
                && ('' === $ipAddress || $row['ip'] === $ipAddress)
                && (null === $username || $row['username'] === $username),
        ));

        return array_reverse($rows);
    }

    private function entity(string $ip, ?string $username, \DateTimeImmutable $at): LoginAttempt
    {
        $attempt = new LoginAttempt($ip, $username);
        (new \ReflectionProperty(LoginAttempt::class, 'createdAt'))->setValue($attempt, $at);

        return $attempt;
    }
}
