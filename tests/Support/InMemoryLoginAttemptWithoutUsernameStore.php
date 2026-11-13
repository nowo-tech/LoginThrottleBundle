<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptWithoutUsernameReaderInterface;

/**
 * The same store, also implementing the username-less read port (3.13).
 */
final class InMemoryLoginAttemptWithoutUsernameStore extends InMemoryLoginAttemptStore implements LoginAttemptWithoutUsernameReaderInterface
{
    public int $getAttemptsCalls = 0;

    public function getAttempts(string $ipAddress, ?string $username, int $seconds): array
    {
        ++$this->getAttemptsCalls;

        return parent::getAttempts($ipAddress, $username, $seconds);
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
     * @return list<array{ip: string, username: ?string, at: \DateTimeImmutable}>
     */
    private function withoutUsername(string $ipAddress, int $seconds): array
    {
        return array_values(array_filter(
            $this->matching($ipAddress, null, $seconds),
            static fn (array $row): bool => null === $row['username'],
        ));
    }
}
