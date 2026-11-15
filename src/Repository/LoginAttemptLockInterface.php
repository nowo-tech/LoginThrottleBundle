<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Serialises the decisions about one IP address (3.15, Beacon spec 239).
 *
 * The database limiter reads the counts of an attempt, records it and reads the counts again; a successful login
 * clears the attempts of its IP + username. When the store implements this interface, each of those units runs inside
 * {@see withAttemptLock()}: no other unit of the same IP address runs at the same time, so N concurrent failed logins
 * are decided one after the other, exactly as if they had arrived one by one (the lockout starts at exactly the
 * configured attempt, never later and never earlier). Every bucket of an attempt (IP + username, IP without a
 * username, the per-IP cap) belongs to one IP address, so one lock per IP covers them all.
 *
 * Implemented by the bundle's {@see LoginAttemptRepository}. A host store that does not implement it keeps the
 * unserialised behaviour: the limiter reads the counts back after recording, so concurrent attempts are never
 * under-counted by the limiter itself, but may all be refused around the threshold.
 *
 * No Doctrine, Symfony or HTTP type crosses it (REQ-ARCH-001 rule 3).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
interface LoginAttemptLockInterface
{
    /**
     * Runs $unit while no other unit of the same IP address runs, and returns what it returned.
     *
     * The unit receives the store to read and write through: inside a transaction the host left open, its reads may
     * have to see rows the host's snapshot does not (locking reads), so the unit must use that store, not the one the
     * method was called on. The unit runs once; it records at most one attempt or clears one bucket.
     *
     * @template T
     *
     * @param string                                                                  $ipAddress The IP address whose attempts the unit reads and writes
     * @param callable(LoginAttemptRepositoryInterface|LoginAttemptStoreInterface): T $unit      The decision or reset
     *
     * @throws TransientStorageFailure When the lock could not be taken in time or the storage failed transiently (nothing of the unit is kept)
     *
     * @return T What the unit returned
     */
    public function withAttemptLock(string $ipAddress, callable $unit): mixed;
}
