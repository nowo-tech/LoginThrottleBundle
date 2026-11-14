<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;

/**
 * A host decorator with a stricter rule (e.g. a deny-list): every bucket is blocked. Implements both store ports so it
 * can decorate either; it never touches the decorated store.
 */
final class DenyAllStore implements LoginAttemptStoreInterface, LoginAttemptRepositoryInterface
{
    public function countAttempts(string $ipAddress, ?string $username, int $seconds): int
    {
        return 100000;
    }

    public function countAttemptsByIp(string $ipAddress, int $seconds): int
    {
        return 100000;
    }

    public function countAttemptsByUsername(string $username, int $seconds): int
    {
        return 100000;
    }

    public function isBlocked(string $ipAddress, ?string $username, int $maxAttempts, int $timeoutSeconds): bool
    {
        return true;
    }

    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
    {
        return new LoginAttempt($ipAddress, $username);
    }

    public function recordAttemptView(string $ipAddress, ?string $username): LoginAttemptView
    {
        return new LoginAttemptView(null, $ipAddress, $username, new \DateTimeImmutable());
    }

    public function clearAttempts(string $ipAddress, ?string $username): int
    {
        return 0;
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
        return [];
    }

    public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array
    {
        return [];
    }

    public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-14T10:00:00+00:00');
    }

    public function countAttemptsWithoutUsername(string $ipAddress, int $seconds): int
    {
        return 100000;
    }

    public function findOldestAttemptWithoutUsernameAt(string $ipAddress, int $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-14T10:00:00+00:00');
    }
}
