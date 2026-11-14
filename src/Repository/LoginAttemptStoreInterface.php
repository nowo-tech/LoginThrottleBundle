<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

/**
 * The login attempt store without any Doctrine type (3.14, REQ-ARCH-001 rules 1 and 3).
 *
 * The entity-free twin of the deprecated {@see LoginAttemptRepositoryInterface}: the same counts, checks and
 * deletions, plus the entity-free reads ({@see LoginAttemptViewRepositoryInterface},
 * {@see LoginAttemptWithoutUsernameReaderInterface}) and write ({@see LoginAttemptRecorderInterface}). Nothing in it
 * returns or takes the bundle's Doctrine entity, so a host can keep attempts in its own store (another table, Redis,
 * an in-memory adapter in tests) without building the bundle's entity.
 *
 * Implemented by the bundle's {@see LoginAttemptRepository}. To use your own store, alias this interface to it:
 * the database limiter, the status provider, the info service and the cleanup command then use it, and
 * {@code doctrine.register_mapping: false} is allowed with {@code storage: database} (no {@code login_attempts}
 * table). When a host aliases both this interface and {@see LoginAttemptRepositoryInterface} to its own services,
 * this one wins.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
interface LoginAttemptStoreInterface extends LoginAttemptRecorderInterface, LoginAttemptViewRepositoryInterface, LoginAttemptWithoutUsernameReaderInterface
{
    /**
     * Count failed login attempts for a given IP and username within a time period.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (null: every attempt of the IP)
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
     * Count failed login attempts by username only (ignoring IP).
     *
     * @param string $username Username
     * @param int    $seconds  Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttemptsByUsername(string $username, int $seconds): int;

    /**
     * Whether the IP + username bucket has reached the maximum number of attempts.
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
     * Clear the attempts of an IP + username after a successful login (same contract as
     * {@see LoginAttemptRepositoryInterface::clearAttempts()}: the bundle's store keeps them counting for the IP).
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (null or empty: only rows without a username)
     *
     * @return int Number of affected records
     */
    public function clearAttempts(string $ipAddress, ?string $username): int;

    /**
     * Delete attempts older than the watch period.
     *
     * @param int $watchPeriodSeconds Period in seconds
     *
     * @return int Number of deleted records
     */
    public function cleanup(int $watchPeriodSeconds): int;

    /**
     * Count attempts older than the watch period (same filter as {@see cleanup()}).
     *
     * @param int $watchPeriodSeconds Period in seconds
     *
     * @return int Number of matching records
     */
    public function countOlderThan(int $watchPeriodSeconds): int;
}
