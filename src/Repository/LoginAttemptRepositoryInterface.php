<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;

/**
 * Contract for persisting and querying login attempts.
 *
 * Two of its methods return the Doctrine entity {@see LoginAttempt} ({@see recordAttempt()},
 * {@see getAttempts()}): they are deprecated in favour of the entity-free twins
 * {@see LoginAttemptRecorderInterface::recordAttemptView()} and
 * {@see LoginAttemptViewRepositoryInterface::getAttemptViews()} (REQ-ARCH-001 rule 1), and stay here until 4.0 so
 * existing stores keep working. A store that implements the twins is used through them by the bundle itself.
 *
 * Since 3.14 the whole port has an entity-free twin, {@see LoginAttemptStoreInterface}: implement and alias that one
 * for a new store. This interface keeps working for existing stores (the bundle accepts either) and is removed in
 * 4.0. When a host aliases both to its own services, {@see LoginAttemptStoreInterface} wins.
 *
 * @deprecated since 3.14, implement {@see LoginAttemptStoreInterface} instead; removed in 4.0
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
     *
     * @deprecated since 3.12, implement {@see LoginAttemptRecorderInterface::recordAttemptView()} instead; removed in 4.0
     */
    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt;

    /**
     * Clear login attempts for a given IP and username (e.g. after successful login); since 3.11.0 the bundle repository keeps them for the per-IP count.
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
     * Count login attempts older than the watch period (same filter as {@see cleanup()}).
     *
     * @param int $watchPeriodSeconds Period in seconds
     *
     * @return int Number of matching records
     */
    public function countOlderThan(int $watchPeriodSeconds): int;

    /**
     * Get all attempts for a given IP and username.
     *
     * @param string      $ipAddress IP address (empty string to ignore IP filter)
     * @param string|null $username  Username (optional, null to ignore username filter)
     * @param int         $seconds   Time period in seconds
     *
     * @return list<LoginAttempt>
     *
     * @deprecated since 3.12, implement {@see LoginAttemptViewRepositoryInterface::getAttemptViews()} instead; removed in 4.0
     */
    public function getAttempts(string $ipAddress, ?string $username, int $seconds): array;
}
