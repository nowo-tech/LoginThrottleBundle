<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;

/**
 * Contract for persisting and querying login attempts.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
interface LoginAttemptRepositoryInterface
{
    /**
     * Count failed login attempts for a given IP and username within a time period.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     * @param int         $seconds   Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttempts(string $ipAddress, ?string $username, int $seconds): int;

    /**
     * Count failed login attempts by IP address only (ignoring username).
     *
     * @param string $ipAddress IP address
     * @param int    $seconds   Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttemptsByIp(string $ipAddress, int $seconds): int;

    /**
     * Count failed login attempts by username/email only (ignoring IP).
     *
     * @param string $username Username/email
     * @param int    $seconds  Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttemptsByUsername(string $username, int $seconds): int;

    /**
     * Check if IP/username is blocked (has exceeded max attempts).
     *
     * @param string      $ipAddress      IP address
     * @param string|null $username       Username (optional)
     * @param int         $maxAttempts    Maximum number of attempts
     * @param int         $timeoutSeconds Timeout period in seconds
     *
     * @return bool True if blocked
     */
    public function isBlocked(string $ipAddress, ?string $username, int $maxAttempts, int $timeoutSeconds): bool;

    /**
     * Record a failed login attempt.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     *
     * @return LoginAttempt The created attempt
     */
    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt;

    /**
     * Delete login attempts for a given IP and username (e.g. after successful login).
     *
     * When username is null or empty, only rows with a null username are removed.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     *
     * @return int Number of deleted records
     */
    public function clearAttempts(string $ipAddress, ?string $username): int;

    /**
     * Clean up old login attempts (older than watch period).
     *
     * @param int $watchPeriodSeconds Period in seconds
     *
     * @return int Number of deleted records
     */
    public function cleanup(int $watchPeriodSeconds): int;

    /**
     * Get all attempts for a given IP and username.
     *
     * @param string      $ipAddress IP address (empty string to ignore IP filter)
     * @param string|null $username  Username (optional, null to ignore username filter)
     * @param int         $seconds   Time period in seconds
     *
     * @return list<LoginAttempt>
     */
    public function getAttempts(string $ipAddress, ?string $username, int $seconds): array;
}
