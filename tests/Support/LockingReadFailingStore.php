<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptLockInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;

/**
 * A store whose plain reads fail with the given failure (3.16): the refusal pre-read of the limiter, outside the lock.
 */
final class LockingReadFailingStore implements LoginAttemptLockInterface, LoginAttemptStoreInterface
{
    /** @var list<string> The IP address of every unit, in order */
    public array $units = [];

    public function __construct(private readonly \Throwable $failure, public readonly EntityFreeLoginAttemptStore $inner = new EntityFreeLoginAttemptStore())
    {
    }

    public function withAttemptLock(string $ipAddress, callable $unit): mixed
    {
        $this->units[] = $ipAddress;

        return $unit($this->inner);
    }

    public function countAttempts(string $ipAddress, ?string $username, int $seconds): int
    {
        return $this->inner->countAttempts($ipAddress, $username, $seconds);
    }

    public function countAttemptsByIp(string $ipAddress, int $seconds): int
    {
        return $this->inner->countAttemptsByIp($ipAddress, $seconds);
    }

    public function countAttemptsByUsername(string $username, int $seconds): int
    {
        return $this->inner->countAttemptsByUsername($username, $seconds);
    }

    public function isBlocked(string $ipAddress, ?string $username, int $maxAttempts, int $timeoutSeconds): bool
    {
        throw $this->failure;
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
        return $this->inner->countOlderThan($watchPeriodSeconds);
    }

    public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array
    {
        return $this->inner->getAttemptViews($ipAddress, $username, $seconds);
    }

    public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): ?\DateTimeImmutable
    {
        return $this->inner->findOldestAttemptAt($ipAddress, $username, $seconds);
    }

    public function countAttemptsWithoutUsername(string $ipAddress, int $seconds): int
    {
        return $this->inner->countAttemptsWithoutUsername($ipAddress, $seconds);
    }

    public function findOldestAttemptWithoutUsernameAt(string $ipAddress, int $seconds): ?\DateTimeImmutable
    {
        return $this->inner->findOldestAttemptWithoutUsernameAt($ipAddress, $seconds);
    }

    private function outside(): \LogicException
    {
        return new \LogicException('Read or written outside a unit.');
    }
}
