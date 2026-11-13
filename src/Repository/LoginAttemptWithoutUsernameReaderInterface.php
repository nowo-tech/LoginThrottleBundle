<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

/**
 * Reads of the attempts an IP made without a usable username (3.13.0), for `max_count_attempts_without_username`.
 *
 * Implemented by {@see LoginAttemptRepository}. A host store that implements {@see LoginAttemptRepositoryInterface}
 * can implement this one too; without it the bundle filters {@see LoginAttemptViewRepositoryInterface::getAttemptViews()}
 * (or the deprecated {@see LoginAttemptRepositoryInterface::getAttempts()}) itself, so existing stores keep working.
 *
 * "Without a username" means the stored username is NULL: attempts recorded without a usable username, and failures
 * a successful login cleared (3.11.0: they keep their row without the username).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
interface LoginAttemptWithoutUsernameReaderInterface
{
    /**
     * Number of attempts of exactly this IP without a username within the window.
     *
     * @param string $ipAddress IP address
     * @param int    $seconds   Time window in seconds
     */
    public function countAttemptsWithoutUsername(string $ipAddress, int $seconds): int;

    /**
     * Time of the oldest attempt of exactly this IP without a username within the window.
     *
     * @param string $ipAddress IP address
     * @param int    $seconds   Time window in seconds
     *
     * @return \DateTimeImmutable|null The oldest attempt time, or null without such attempts
     */
    public function findOldestAttemptWithoutUsernameAt(string $ipAddress, int $seconds): ?\DateTimeImmutable;
}
