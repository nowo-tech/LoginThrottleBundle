<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

/**
 * The bundle's runner for the units of one IP address (3.15, Beacon spec 239): an attempt decision (read the counts,
 * record, read them again) or the reset after a successful login.
 *
 * Two things together make the units of one IP address run one after the other, on every supported server:
 *
 * - a **lock per IP address**, taken before the unit reads anything:
 *   - MySQL / MariaDB: `GET_LOCK()` (a user lock of the session, taken before the transaction starts and always released
 *     in `finally`, also after a failure; when the release fails or reports a lock this session did not hold, outside a
 *     transaction of the host, the connection is closed so the server drops the lock rather than a worker keeping it,
 *     audit lessons QG-M2 and QU-L1). The unit's own transaction is READ COMMITTED and reads with locking reads (audit
 *     QU-M2). With DoctrineBundle `replicas:` the connection is pinned to the primary first;
 *   - PostgreSQL: `pg_try_advisory_xact_lock()`, polled until it is granted (the unit's own transaction is READ
 *     COMMITTED, so its reads see what the previous holder committed): held until the end of the transaction, so
 *     inside a transaction the host left open it serialises the host's attempts until the host commits. It never
 *     waits on `lock_timeout` (which would abort the host's transaction on PostgreSQL) and changes no setting;
 *   - SQLite: an empty `UPDATE` of the attempt table, which takes the database's write lock first (a plain read first
 *     would let two processes deadlock when both upgrade to the write lock);
 *   - other platforms: no lock (the limiter still reads the counts back after recording, see the HIGH-LOAD document);
 * - **one transaction per unit**, opened and committed by transaction-doctrine's DBAL runner
 *   ({@see DoctrineTransactionRunner::forConnection()}) at READ COMMITTED: the reads of the unit start after the lock
 *   and see every attempt committed before. After any failure nothing of the unit is kept and
 *   no transaction of the bundle stays open; a transient failure (deadlock, lock wait timeout, serialization failure)
 *   becomes {@see TransientStorageFailure}.
 *
 * Inside a transaction the host left open the unit runs in it, in an explicit savepoint of its own (no runner: the
 * host's nesting level is unchanged, so a DBAL 3 host without `use_savepoints` never sees its transaction marked
 * rollback-only by a bundle failure, and a failed unit keeps nothing even if the host commits afterwards). On MySQL / MariaDB the caller reads with locking reads there too
 * ({@see self::needsLockingReads()}): the host's snapshot may predate attempts that other requests committed. Under a
 * host REPEATABLE READ transaction those locking reads take gap locks that last until the host commits, so an attempt of
 * a neighbouring IP address may wait for that commit (docs/HIGH-LOAD.md, Known issues).
 *
 * The bundle never flushes, clears, closes or resets an entity manager here: every statement is DBAL.
 *
 * Holds no per-call state: one instance serves every request of a long-running worker.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final readonly class AttemptLockRunner
{
    /**
     * Seconds a unit waits for the lock of its IP address before it gives up with a transient failure (3.15 default,
     * option {@code attempt_lock_timeout}). A unit takes milliseconds, so a long wait only lengthens an attacker's queue
     * (audit QU-M1); an attempt that a limit already refuses never waits for the lock at all.
     */
    public const LOCK_TIMEOUT_SECONDS = 3;

    /** The Retry-After hint of the transient failures, in seconds. */
    public const RETRY_AFTER_SECONDS = 1;

    /** First pause between two PostgreSQL lock polls, in microseconds; it doubles up to {@see self::POLL_CAP_MICROSECONDS}. */
    private const POLL_BASE_MICROSECONDS = 2_000;

    private const POLL_CAP_MICROSECONDS = 50_000;

    /**
     * @param int       $lockTimeoutSeconds Seconds a unit waits for the lock of its IP address (at least one)
     * @param bool|null $lockingReads       Whether a unit reads with locking reads: null (the default) on MySQL / MariaDB
     *                                      only; true for a MySQL-compatible server DBAL does not detect as MySQL
     */
    public function __construct(private int $lockTimeoutSeconds = self::LOCK_TIMEOUT_SECONDS, private ?bool $lockingReads = null)
    {
    }

    /**
     * Runs $unit under the lock of $ipAddress, in its own transaction (or in the host's open one).
     *
     * @template T
     *
     * @param Connection    $connection The connection of the attempt table
     * @param string        $table      The attempt table (part of the lock name, and the SQLite write lock's target)
     * @param string        $ipAddress  The IP address of the unit
     * @param callable(): T $unit       The unit
     *
     * @throws TransientStorageFailure When the lock was not granted in time, or the storage failed transiently
     *
     * @return T What the unit returned
     */
    public function run(Connection $connection, string $table, string $ipAddress, callable $unit): mixed
    {
        if ($connection instanceof PrimaryReadReplicaConnection) {
            // DoctrineBundle `replicas:` (audit QU-L1): the lock, the unit and the release on one session, the primary's
            // (a lock taken on a replica session would be released on the primary, or leak with keep_replica)
            $connection->ensureConnectedToPrimary();
        }
        $platform = $connection->getDatabasePlatform();
        $nested = $connection->isTransactionActive();

        if ($platform instanceof AbstractMySQLPlatform) {
            $name = self::lockName($table, $ipAddress);
            $this->acquireUserLock($connection, $name);

            try {
                if ($nested) {
                    return $this->inSavepoint($connection, $unit);
                }
                // audit QU-M2: the unit's own transaction is READ COMMITTED, so its locking reads take record locks only
                // (no gap lock stalls another IP address) and wait for an attempt a host transaction has not committed
                // yet, then see it
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

                return DoctrineTransactionRunner::forConnection($connection, self::RETRY_AFTER_SECONDS)->run($unit);
            } finally {
                $this->releaseUserLock($connection, $name);
            }
        }

        $locked = function () use ($connection, $platform, $table, $ipAddress, $unit, $nested): mixed {
            if ($platform instanceof PostgreSQLPlatform) {
                if (!$nested) {
                    // the unit's own transaction reads what was committed before each statement, whatever the server's
                    // default_transaction_isolation: under REPEATABLE READ the first lock poll would fix a snapshot
                    // taken before the previous holder committed
                    $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $this->acquireAdvisoryLock($connection, self::lockKey($table, $ipAddress));
            } elseif ($platform instanceof SQLitePlatform) {
                // the database write lock first: SQLite's busy timeout then queues the other writers
                $connection->executeStatement(\sprintf('UPDATE %s SET ip_address = ip_address WHERE 1 = 0', $platform->quoteSingleIdentifier($table)));
            }

            return $unit();
        };

        return $nested ? $this->inSavepoint($connection, $locked) : DoctrineTransactionRunner::forConnection($connection, self::RETRY_AFTER_SECONDS)->run($locked);
    }

    /**
     * Runs a unit nested in the host's transaction inside a savepoint of its own, so a failed unit keeps nothing (an
     * attempt it already recorded is rolled back with it) even when the host catches the failure and commits. Explicit
     * savepoint calls: the host's nesting level is unchanged, whatever DBAL 3's `use_savepoints` says. The name is unique
     * per call (on MySQL / MariaDB a SAVEPOINT with an existing name replaces it).
     *
     * @template T
     *
     * @param callable(): T $unit
     *
     * @return T
     */
    private function inSavepoint(Connection $connection, callable $unit): mixed
    {
        $savepoint = 'nowo_login_throttle_unit_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);

        try {
            $result = $unit();
        } catch (\Throwable $failure) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (\Throwable) {
                // a deadlock or lock wait timeout may already have rolled back the host's whole transaction (and the
                // savepoint with it): the original failure is the one to report
            }

            throw $failure;
        }
        $connection->releaseSavepoint($savepoint);

        return $result;
    }

    /**
     * Whether a unit on this connection must read with locking reads: MySQL / MariaDB, in its own READ COMMITTED
     * transaction and inside a transaction the host left open alike. A locking read sees past a REPEATABLE READ
     * snapshot, and waits for an attempt that a host transaction has recorded but not committed yet (audit QU-M2: the
     * user lock is released before the host commits). PostgreSQL's advisory lock lasts until the commit of the
     * transaction that recorded the attempt, so its reads never miss one.
     *
     * @param Connection $connection The connection of the attempt table
     */
    public function needsLockingReads(Connection $connection): bool
    {
        return $this->lockingReads ?? $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    /**
     * The MySQL user lock name of an IP address (64 characters at most, per table so two applications on one server
     * never wait on each other).
     */
    public static function lockName(string $table, string $ipAddress): string
    {
        return 'nowo_login_throttle_' . sha1($table . "\0" . $ipAddress);
    }

    /**
     * The PostgreSQL advisory lock key of an IP address (a signed 64-bit integer, the same on every machine).
     */
    public static function lockKey(string $table, string $ipAddress): int
    {
        /** @var array{1: int} $key */
        $key = unpack('J', substr(sha1('nowo_login_throttle' . "\0" . $table . "\0" . $ipAddress, true), 0, 8));

        return $key[1];
    }

    private function acquireUserLock(Connection $connection, string $name): void
    {
        $granted = $connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, max(1, $this->lockTimeoutSeconds)]);
        // 1: granted; 0: timed out; NULL: an error (the session was killed, out of memory)
        if (1 !== (int) $granted) {
            throw new TransientStorageFailure(self::RETRY_AFTER_SECONDS);
        }
    }

    private function releaseUserLock(Connection $connection, string $name): void
    {
        $failure = null;

        try {
            // 1: released; 0: held by another session; NULL: no such lock on this session (audit QU-L1)
            $released = 1 === (int) $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (\Throwable $failure) {
            $released = false;
        }
        if ($released) {
            return;
        }
        if ($connection->isTransactionActive()) {
            // the host's transaction: its connection is not the bundle's to close
            throw $failure ?? new \RuntimeException('The login throttle lock of the IP address was not held by this session at release.');
        }
        // a lock the worker could not release would block this IP address for as long as the worker lives: closing the
        // session drops every user lock it holds on the server
        $connection->close();
    }

    private function acquireAdvisoryLock(Connection $connection, int $key): void
    {
        $deadline = microtime(true) + max(1, $this->lockTimeoutSeconds);
        $pause = self::POLL_BASE_MICROSECONDS;
        while (!$connection->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [$key])) {
            if (microtime(true) >= $deadline) {
                throw new TransientStorageFailure(self::RETRY_AFTER_SECONDS);
            }
            usleep($pause);
            $pause = min(self::POLL_CAP_MICROSECONDS, $pause * 2);
        }
    }
}
