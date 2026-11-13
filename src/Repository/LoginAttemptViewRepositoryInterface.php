<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

/**
 * Entity-free reads of the login attempt store (3.9).
 *
 * Implemented by {@see LoginAttemptRepository}. A host store that implements {@see LoginAttemptRepositoryInterface}
 * can implement this one too; the limiter and the status provider then use it instead of {@see
 * LoginAttemptRepositoryInterface::getAttempts()} (which returns entities). Kept separate so existing implementations
 * of {@see LoginAttemptRepositoryInterface} stay valid. Its write-side counterpart is
 * {@see LoginAttemptRecorderInterface} (3.12).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
interface LoginAttemptViewRepositoryInterface
{
    /**
     * Attempts for a given IP and username within the window, newest first.
     *
     * @param string      $ipAddress IP address (empty string to ignore the IP filter)
     * @param string|null $username  Username (null to ignore the username filter)
     * @param int         $seconds   Time window in seconds
     *
     * @return list<LoginAttemptView>
     */
    public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array;

    /**
     * Time of the oldest attempt for a given IP and username within the window.
     *
     * @param string      $ipAddress IP address (empty string to ignore the IP filter)
     * @param string|null $username  Username (null to ignore the username filter)
     * @param int         $seconds   Time window in seconds
     *
     * @return \DateTimeImmutable|null The oldest attempt time, or null without attempts
     */
    public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): ?\DateTimeImmutable;
}
