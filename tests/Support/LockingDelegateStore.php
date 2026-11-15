<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptLockInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;

/**
 * A host store with a lock (3.15) that hands its units ANOTHER store: every read or write outside a unit fails, so a
 * test proves that the limiter decides and resets through the store the unit received.
 */
final class LockingDelegateStore implements LoginAttemptLockInterface, LoginAttemptStoreInterface
{
    /** @var list<string> The IP address of every unit, in order */
    public array $units = [];

    public function __construct(public readonly EntityFreeLoginAttemptStore $inner = new EntityFreeLoginAttemptStore())
    {
    }

    public function withAttemptLock(string $ipAddress, callable $unit): mixed
    {
        $this->units[] = $ipAddress;

        return $unit($this->inner);
    }

    public function countAttempts(string $ipAddress, ?string $username, int $seconds): int
    {
        throw $this->outside();
    }

    public function countAttemptsByIp(string $ipAddress, int $seconds): int
    {
        throw $this->outside();
    }

    public function countAttemptsByUsername(string $username, int $seconds): int
    {
        throw $this->outside();
    }

    public function isBlocked(string $ipAddress, ?string $username, int $maxAttempts, int $timeoutSeconds): bool
    {
        throw $this->outside();
    }

    public function recordAttemptView(string $ipAddress, ?string $username): LoginAttemptView
    {
        throw $this->outside();
    }

    public function clearAttempts(string $ipAddress, ?string $username): int
    {
        throw $this->outside();
    }

    public function cleanup(int $watchPeriodSeconds): int
    {
        throw $this->outside();
    }

    public function countOlderThan(int $watchPeriodSeconds): int
    {
        throw $this->outside();
    }

    public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array
    {
        throw $this->outside();
    }

    public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): ?\DateTimeImmutable
    {
        throw $this->outside();
    }

    public function countAttemptsWithoutUsername(string $ipAddress, int $seconds): int
    {
        throw $this->outside();
    }

    public function findOldestAttemptWithoutUsernameAt(string $ipAddress, int $seconds): ?\DateTimeImmutable
    {
        throw $this->outside();
    }

    private function outside(): \LogicException
    {
        return new \LogicException('Read or written outside a unit.');
    }
}
