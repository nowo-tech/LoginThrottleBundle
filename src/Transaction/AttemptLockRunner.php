<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Transaction;

use Doctrine\DBAL\Connection;
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
 *     in `finally`, also after a failure; when the release itself fails outside a transaction of the host, the
 *     connection is closed so the server drops the lock rather than a worker keeping it, audit lesson QG-M2);
 *   - PostgreSQL: `pg_try_advisory_xact_lock()`, polled until it is granted (the unit's own transaction is READ
 *     COMMITTED, so its reads see what the previous holder committed): held until the end of the transaction, so
 *     inside a transaction the host left open it serialises the host's attempts until the host commits. It never
 *     waits on `lock_timeout` (which would abort the host's transaction on PostgreSQL) and changes no setting;
 *   - SQLite: an empty `UPDATE` of the attempt table, which takes the database's write lock first (a plain read first
 *     would let two processes deadlock when both upgrade to the write lock);
 *   - other platforms: no lock (the limiter still reads the counts back after recording, see the HIGH-LOAD document);
 * - **one transaction per unit**, opened and committed by transaction-doctrine's DBAL runner
 *   ({@see DoctrineTransactionRunner::forConnection()}): the reads of the unit start after the lock, so even MySQL's
 *   REPEATABLE READ snapshot holds every attempt committed before. After any failure nothing of the unit is kept and
 *   no transaction of the bundle stays open; a transient failure (deadlock, lock wait timeout, serialization failure)
 *   becomes {@see TransientStorageFailure}.
 *
 * Inside a transaction the host left open the unit runs directly in it (no runner, no savepoint of its own: the
 * attempt keeps its own savepoint, as before 3.15, so a DBAL 3 host without savepoints never sees its transaction
 * marked rollback-only by a bundle failure). The caller then reads with locking reads on MySQL / MariaDB
 * ({@see self::needsLockingReads()}): the host's snapshot may predate attempts that other requests committed, and a
 * locking read also waits for an attempt another open transaction has not committed yet.
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
    /** Seconds a unit waits for the lock of its IP address before it gives up with a transient failure. */
    public const LOCK_TIMEOUT_SECONDS = 10;

    /** The Retry-After hint of the transient failures, in seconds. */
    public const RETRY_AFTER_SECONDS = 1;

    /** First pause between two PostgreSQL lock polls, in microseconds; it doubles up to {@see self::POLL_CAP_MICROSECONDS}. */
    private const POLL_BASE_MICROSECONDS = 2_000;

    private const POLL_CAP_MICROSECONDS = 50_000;

    /**
     * @param int       $lockTimeoutSeconds Seconds a unit waits for the lock of its IP address (at least one)
     * @param bool|null $lockingReads       Whether a unit inside the host's transaction reads with locking reads: null
     *                                      (the default) on MySQL / MariaDB only; true for a MySQL-compatible server DBAL
     *                                      does not detect as MySQL
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
        $platform = $connection->getDatabasePlatform();
        $nested = $connection->isTransactionActive();

        if ($platform instanceof AbstractMySQLPlatform) {
            $name = self::lockName($table, $ipAddress);
            $this->acquireUserLock($connection, $name);

            try {
                return $nested ? $unit() : DoctrineTransactionRunner::forConnection($connection, self::RETRY_AFTER_SECONDS)->run($unit);
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

        return $nested ? $locked() : DoctrineTransactionRunner::forConnection($connection, self::RETRY_AFTER_SECONDS)->run($locked);
    }

    /**
     * Whether a unit on this connection must read with locking reads: MySQL / MariaDB inside a transaction the host
     * left open (its REPEATABLE READ snapshot may hide committed attempts; a locking read sees them, and waits for an
     * attempt that another open transaction has not committed yet). A unit in its own transaction reads after the lock,
     * so its snapshot already holds every committed attempt; PostgreSQL's advisory lock lasts until the commit of the
     * transaction that recorded the attempt, so its reads never miss one either.
     *
     * @param Connection $connection The connection of the attempt table
     * @param bool       $nested     Whether the unit runs inside the host's transaction
     */
    public function needsLockingReads(Connection $connection, bool $nested): bool
    {
        return $nested && ($this->lockingReads ?? $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform);
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
        try {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (\Throwable $failure) {
            if ($connection->isTransactionActive()) {
                // the host's transaction: its connection is not the bundle's to close
                throw $failure;
            }
            // a lock the worker could not release would block this IP address for as long as the worker lives:
            // closing the session drops it on the server
            $connection->close();
        }
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
