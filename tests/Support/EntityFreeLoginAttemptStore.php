<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;

/**
 * DDD-host store (3.14): implements only the entity-free {@see LoginAttemptStoreInterface}, never imports the bundle's
 * Doctrine entity, keeps rows in memory on a settable clock (same filters as the bundle repository).
 */
final class EntityFreeLoginAttemptStore implements LoginAttemptStoreInterface
{
    /** @var list<array{id: int, ip: string, username: ?string, at: \DateTimeImmutable}> */
    private array $rows = [];

    private int $nextId = 1;

    public \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-11-14T10:00:00+00:00');
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

    public function recordAttemptView(string $ipAddress, ?string $username): LoginAttemptView
    {
        $row = ['id' => $this->nextId++, 'ip' => $ipAddress, 'username' => $username, 'at' => $this->now];
        $this->rows[] = $row;

        return $this->view($row);
    }

    /**
     * The binding store contract (3.11.0 rule): the newest matching row is deleted, the older ones lose their
     * username and keep counting for the IP. Without a username, the IP's rows without one are deleted.
     */
    public function clearAttempts(string $ipAddress, ?string $username): int
    {
        $key = '' === $username ? null : $username;
        $matching = [];
        foreach ($this->rows as $index => $row) {
            if ($row['ip'] === $ipAddress && $row['username'] === $key) {
                $matching[] = $index;
            }
        }

        if ([] === $matching) {
            return 0;
        }

        // newest first: by time, then by insertion order
        usort($matching, fn (int $a, int $b): int => [$this->rows[$b]['at'], $this->rows[$b]['id']] <=> [$this->rows[$a]['at'], $this->rows[$a]['id']]);
        $newest = array_shift($matching);
        foreach ($matching as $index) {
            if (null === $key) {
                unset($this->rows[$index]);

                continue;
            }
            $this->rows[$index]['username'] = null;
        }
        unset($this->rows[$newest]);
        $this->rows = array_values($this->rows);

        return \count($matching) + 1;
    }

    public function cleanup(int $watchPeriodSeconds): int
    {
        $before = \count($this->rows);
        $since = $this->now->modify('-' . $watchPeriodSeconds . ' seconds');
        $this->rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['at'] >= $since));

        return $before - \count($this->rows);
    }

    public function countOlderThan(int $watchPeriodSeconds): int
    {
        $since = $this->now->modify('-' . $watchPeriodSeconds . ' seconds');

        return \count(array_filter($this->rows, static fn (array $row): bool => $row['at'] < $since));
    }

    public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array
    {
        return array_map($this->view(...), $this->matching($ipAddress, $username, $seconds));
    }

    public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): ?\DateTimeImmutable
    {
        $rows = $this->matching($ipAddress, $username, $seconds);

        return [] === $rows ? null : $rows[array_key_last($rows)]['at'];
    }

    public function countAttemptsWithoutUsername(string $ipAddress, int $seconds): int
    {
        return \count($this->withoutUsername($ipAddress, $seconds));
    }

    public function findOldestAttemptWithoutUsernameAt(string $ipAddress, int $seconds): ?\DateTimeImmutable
    {
        $rows = $this->withoutUsername($ipAddress, $seconds);

        return [] === $rows ? null : $rows[array_key_last($rows)]['at'];
    }

    /**
     * @return list<array{id: int, ip: string, username: ?string, at: \DateTimeImmutable}>
     */
    private function withoutUsername(string $ipAddress, int $seconds): array
    {
        return array_values(array_filter(
            $this->matching($ipAddress, null, $seconds),
            static fn (array $row): bool => null === $row['username'],
        ));
    }

    /**
     * Newest first, like the Doctrine repository.
     *
     * @return list<array{id: int, ip: string, username: ?string, at: \DateTimeImmutable}>
     */
    private function matching(string $ipAddress, ?string $username, int $seconds): array
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

    /**
     * @param array{id: int, ip: string, username: ?string, at: \DateTimeImmutable} $row
     */
    private function view(array $row): LoginAttemptView
    {
        return new LoginAttemptView($row['id'], $row['ip'], $row['username'], $row['at']);
    }
}
