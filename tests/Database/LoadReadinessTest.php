<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Tests\Database\Support\AttemptDatabase;
use Nowo\LoginThrottleBundle\Tests\Database\Support\WorkerProcess;
use Nowo\LoginThrottleBundle\Transaction\AttemptLockRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;

/**
 * Concurrent logins on a real server (3.15, Beacon spec 239): separate PHP processes, each on its own connection, like
 * the requests of different workers. Runs when LOGIN_THROTTLE_TEST_DATABASE_URL points at a throwaway MySQL / MariaDB
 * or PostgreSQL database (the attempt table is dropped first); CI job "Load readiness" runs it with
 * `--fail-on-skipped`.
 *
 * The tests of a race use only API that existed in 3.14.0: run against 3.14.0 they fail (see docs/HIGH-LOAD.md).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoadReadinessTest extends TestCase
{
    private const IP = '203.0.113.7';

    /** Seconds a worker blocked behind the lock of its IP address must stay silent. */
    private const HELD_BACK_SECONDS = 1.5;

    private string $url;

    private EntityManager $entityManager;

    /** @var list<WorkerProcess> */
    private array $workers = [];

    protected function setUp(): void
    {
        $url = AttemptDatabase::url();
        if (null === $url) {
            $this->markTestSkipped(AttemptDatabase::URL_VARIABLE . ' is not set.');
        }
        $this->url = $url;
        $this->entityManager = AttemptDatabase::entityManager($url);
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            // SQLite serialises every write of the database, whatever the IP address (see docs/HIGH-LOAD.md)
            $this->markTestSkipped('MySQL, MariaDB or PostgreSQL only.');
        }
        AttemptDatabase::createSchema($this->entityManager);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->close();
        }
        $this->workers = [];
    }

    /**
     * N parallel failed logins of one username: exactly max - 1 are let through to the password check, the lockout
     * starts at exactly the max-th, and no attempt is lost or doubled (3.14.0: the attempts that raced around the
     * threshold were all refused, or recorded past it).
     */
    public function testParallelFailuresOfOneUsernameLockOutAtExactlyTheThreshold(): void
    {
        $this->assertBurst('alice', 5, 1000, null, 4, 5);
    }

    /**
     * A client rotating usernames from one IP address: exactly cap - 1 attempts pass the per-IP cap.
     */
    public function testParallelFailuresRotatingUsernamesStopAtExactlyThePerIpCap(): void
    {
        $this->assertBurst('rotating*', 1000, 6, null, 5, 6);
    }

    /**
     * Requests without a username, under the tighter bound of 3.13.0.
     */
    public function testParallelFailuresWithoutAUsernameStopAtExactlyTheirBound(): void
    {
        $this->assertBurst('-', 1000, 1000, 3, 2, 3);
    }

    /**
     * An attempt paused right after its insert keeps the next attempt of its IP address waiting until it is decided,
     * so the earlier attempt is decided as if it had been alone (3.14.0: the second attempt saw the first one's row,
     * locked the username out, and the first was refused too: an attempt below the threshold refused).
     */
    public function testAnAttemptBeingDecidedHoldsBackTheNextAttemptOfItsIp(): void
    {
        $this->seed('alice', 1);
        $first = $this->worker('paused', 'alice', 3, 1000, ['3']);
        $first->expect('PAUSED');

        $second = $this->worker('burst', 'alice', 3, 1000, ['1']);
        $second->expect('READY');
        $second->send();
        $this->assertNull($second->line(self::HELD_BACK_SECONDS), 'the second attempt waits for the lock of the IP address');

        // another IP address is not held back
        $other = $this->worker('burst', 'alice', 3, 1000, ['1'], '198.51.100.9');
        $other->expect('READY');
        $other->send();
        $other->expect('accepted');

        $first->send();
        $this->assertSame(['accepted'], $first->rest());
        $this->assertSame(['refused'], $second->rest(), 'the third attempt starts the lockout');
        $this->assertSame(3, $this->rows('alice'));
    }

    /**
     * An attempt recorded inside a transaction a host left open (not committed yet) is counted by the next attempt of
     * the same IP address, which waits for that commit (3.14.0: the second attempt did not see it and was let through,
     * one attempt past the threshold).
     */
    public function testAnAttemptInAnOpenHostTransactionIsCountedByTheNextOne(): void
    {
        $first = $this->worker('host', 'alice', 2, 1000, ['hold']);
        $first->expect('accepted');
        $first->expect('HELD');

        $second = $this->worker('host', 'alice', 2, 1000, ['commit']);
        $this->assertNull($second->line(self::HELD_BACK_SECONDS), 'the second attempt waits for the first one to commit');

        $first->send();
        $this->assertSame([], $first->rest());
        $this->assertSame(['refused'], $second->rest(), 'the second attempt reaches the threshold of 2');
        $this->assertSame(2, $this->rows('alice'));
    }

    /**
     * A host transaction that read before the login (MySQL's REPEATABLE READ snapshot starts there) still counts the
     * attempts committed since (3.14.0 on MySQL: the snapshot hid them and the attempt was let through past the
     * threshold; PostgreSQL's READ COMMITTED never hid them).
     */
    public function testAHostSnapshotDoesNotHideAttemptsCommittedSince(): void
    {
        $host = $this->worker('snapshot', 'alice', 3, 1000);
        $host->expect('READY');

        $limiter = AttemptDatabase::limiter($this->repository(), 3, 1000);
        $this->assertTrue($limiter->consume(AttemptDatabase::request(self::IP, 'alice'))->isAccepted());
        $this->assertTrue($limiter->consume(AttemptDatabase::request(self::IP, 'alice'))->isAccepted());

        $host->send();
        $this->assertSame(['refused'], $host->rest(), 'the third attempt starts the lockout');
        $this->assertSame(3, $this->rows('alice'));
    }

    /**
     * A successful login of the IP + username waits for the failed attempt of the same bucket being decided: the
     * failure is decided with its own row counted, then the reset clears the bucket (3.14.0: the reset deleted the
     * failure's row in the middle of its decision, which then read a count of 0 and let it through).
     */
    public function testAResetWaitsForTheAttemptBeingDecided(): void
    {
        $this->seed('alice', 1);
        $failure = $this->worker('paused', 'alice', 2, 1000, ['3']);
        $failure->expect('PAUSED');

        $success = $this->worker('reset', 'alice', 2, 1000);
        $success->expect('READY');
        $success->send();
        $this->assertNull($success->line(self::HELD_BACK_SECONDS), 'the reset waits for the lock of the IP address');

        $failure->send();
        $this->assertSame(['refused'], $failure->rest(), 'the second failure reaches the threshold of 2');
        $this->assertSame(['reset'], $success->rest());
        $this->assertSame(0, $this->rows('alice'));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM login_attempts'), 'the older failure keeps counting for the IP');
    }

    /**
     * A lock the unit cannot get in time ends in TransientStorageFailure, and nothing is recorded (fail closed: the
     * attempt never reaches the password check).
     */
    public function testALockNotGrantedInTimeFailsClosed(): void
    {
        $holder = AttemptDatabase::entityManager($this->url)->getConnection();
        $platform = $holder->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->assertSame(1, (int) $holder->fetchOne('SELECT GET_LOCK(?, 0)', [AttemptLockRunner::lockName('login_attempts', self::IP)]));
        } else {
            $holder->beginTransaction();
            $holder->fetchOne('SELECT pg_advisory_xact_lock(?)', [AttemptLockRunner::lockKey('login_attempts', self::IP)]);
        }

        $repository = new LoginAttemptRepository(AttemptDatabase::registry($this->entityManager), null, new AttemptLockRunner(1));
        $started = microtime(true);

        try {
            AttemptDatabase::limiter($repository, 3, 1000)->consume(AttemptDatabase::request(self::IP, 'alice'));
            $this->fail('The attempt must not be decided without the lock.');
        } catch (TransientStorageFailure $failure) {
            $this->assertSame(1, $failure->retryAfterSeconds());
        } finally {
            $holder->close();
        }

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        $this->assertSame(0, $this->rows('alice'));
        $this->assertFalse($this->entityManager->getConnection()->isTransactionActive());
        $this->assertTrue(AttemptDatabase::limiter($repository, 3, 1000)->consume(AttemptDatabase::request(self::IP, 'alice'))->isAccepted(), 'the lock is free again');
    }

    private function assertBurst(string $username, int $maxAttempts, int $perIpCap, ?int $withoutUsername, int $accepted, int $rows): void
    {
        $workers = [];
        for ($i = 0; $i < 4; ++$i) {
            $workers[] = $worker = $this->worker('burst', $username, $maxAttempts, $perIpCap, ['8', null === $withoutUsername ? '-' : (string) $withoutUsername]);
            $worker->expect('READY');
        }
        foreach ($workers as $worker) {
            $worker->send();
        }

        $outcomes = [];
        foreach ($workers as $worker) {
            $outcomes = [...$outcomes, ...$worker->rest()];
        }

        $this->assertCount(32, $outcomes, implode(', ', $outcomes));
        $this->assertSame(32 - $accepted, \count(array_filter($outcomes, static fn (string $outcome): bool => 'refused' === $outcome)), implode(', ', $outcomes));
        $this->assertSame($accepted, \count(array_filter($outcomes, static fn (string $outcome): bool => 'accepted' === $outcome)), 'exactly the attempts below the threshold are let through');
        $this->assertSame($rows, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM login_attempts'), 'the attempts up to the one that started the lockout are recorded, no more');
    }

    /**
     * @param list<string> $arguments
     */
    private function worker(string $command, string $username, int $maxAttempts, int $perIpCap, array $arguments = [], string $ipAddress = self::IP): WorkerProcess
    {
        return $this->workers[] = new WorkerProcess($this->url, $command, [$ipAddress, $username, (string) $maxAttempts, (string) $perIpCap, ...$arguments]);
    }

    private function seed(string $username, int $count): void
    {
        $repository = $this->repository();
        for ($i = 0; $i < $count; ++$i) {
            $repository->recordAttemptView(self::IP, $username);
        }
    }

    private function rows(string $username): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND username = ?', [self::IP, $username]);
    }

    private function repository(): LoginAttemptRepository
    {
        return AttemptDatabase::repository($this->entityManager);
    }
}
