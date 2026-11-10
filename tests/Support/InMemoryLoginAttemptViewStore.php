<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptViewRepositoryInterface;

/**
 * The same store, also implementing the entity-free read port.
 */
final class InMemoryLoginAttemptViewStore extends InMemoryLoginAttemptStore implements LoginAttemptViewRepositoryInterface
{
    public int $getAttemptsCalls = 0;

    public function getAttempts(string $ipAddress, ?string $username, int $seconds): array
    {
        ++$this->getAttemptsCalls;

        return parent::getAttempts($ipAddress, $username, $seconds);
    }

    public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array
    {
        return array_map(
            static fn (array $row): LoginAttemptView => new LoginAttemptView(null, $row['ip'], $row['username'], $row['at']),
            $this->matching($ipAddress, $username, $seconds),
        );
    }

    public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): ?\DateTimeImmutable
    {
        $rows = $this->matching($ipAddress, $username, $seconds);

        return [] === $rows ? null : $rows[array_key_last($rows)]['at'];
    }
}
