<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\LoginThrottleBundle\Transaction\AttemptLockRunner;
use Nowo\LoginThrottleBundle\Transaction\LoginThrottleStorageFailure;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The per-IP lock and the unit transaction of {@see AttemptLockRunner} (3.15), on every platform branch. The real
 * servers are exercised by tests/Database (CI job "Load readiness").
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class AttemptLockRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    public function testMySqlTakesTheUserLockBeforeItsTransactionAndReleasesItAfterTheCommit(): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 1]);

        $result = (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', function (): string {
            $this->log[] = 'unit';

            return 'done';
        });

        $this->assertSame('done', $result);
        $name = AttemptLockRunner::lockName('login_attempts', '10.0.0.1');
        $this->assertSame([
            'SELECT GET_LOCK(?, ?) ' . $name . ' 3',
            'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
            'begin',
            'unit',
            'commit',
            'SELECT RELEASE_LOCK(?) ' . $name,
        ], $this->log);
    }

    public function testMySqlInsideTheHostsTransactionRunsTheUnitInIt(): void
    {
        $connection = $this->connection(new MySQLPlatform(), true, ['GET_LOCK' => 1]);

        (new AttemptLockRunner(3))->run($connection, 'login_attempts', '10.0.0.1', function (): void {
            $this->log[] = 'unit';
        });

        $this->assertSame(['GET_LOCK', 'savepoint', 'unit', 'release savepoint', 'RELEASE_LOCK'], $this->statements(), 'a savepoint of its own, no transaction level');
    }

    public function testMySqlReleasesTheUserLockAfterAFailedUnit(): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 1]);

        try {
            (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static function (): never {
                throw new \DomainException('refused');
            });
            $this->fail('The failure must reach the caller.');
        } catch (\DomainException $failure) {
            $this->assertSame('refused', $failure->getMessage());
        }

        $this->assertSame(['GET_LOCK', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'begin', 'rollBack', 'RELEASE_LOCK'], $this->statements(), 'rolled back, then released');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedUserLocks(): iterable
    {
        yield 'timed out' => [0];
        yield 'error' => [null];
    }

    #[DataProvider('refusedUserLocks')]
    public function testAUserLockNotGrantedFailsTransientlyWithoutRunningTheUnit(mixed $granted): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => $granted]);

        try {
            (new AttemptLockRunner(0))->run($connection, 'login_attempts', '10.0.0.1', function (): void {
                $this->log[] = 'unit';
            });
            $this->fail('The unit must not run without the lock.');
        } catch (TransientStorageFailure $failure) {
            $this->assertSame(AttemptLockRunner::RETRY_AFTER_SECONDS, $failure->retryAfterSeconds());
        }

        $this->assertSame(['SELECT GET_LOCK(?, ?) ' . AttemptLockRunner::lockName('login_attempts', '10.0.0.1') . ' 1'], $this->log, 'at least one second, no release of a lock never taken');
    }

    public function testAUserLockThatCannotBeReleasedClosesTheConnection(): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 1, 'RELEASE_LOCK' => new \RuntimeException('gone away')]);
        $connection->expects($this->once())->method('close');

        $this->assertSame(7, (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static fn (): int => 7));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreleasedUserLocks(): iterable
    {
        yield 'held by another session' => [0];
        yield 'not held by this session (a replica session took it)' => [null];
    }

    /**
     * Audit QU-L1: a release that reports a lock this session did not hold is a failed release.
     */
    #[DataProvider('unreleasedUserLocks')]
    public function testAUserLockReleaseThatReportsNoLockClosesTheConnection(mixed $released): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 1, 'RELEASE_LOCK' => $released]);
        $connection->expects($this->once())->method('close');

        $this->assertSame(7, (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static fn (): int => 7));
    }

    public function testAUserLockReleaseThatReportsNoLockInsideTheHostsTransactionIsReported(): void
    {
        $connection = $this->connection(new MySQLPlatform(), true, ['GET_LOCK' => 1, 'RELEASE_LOCK' => 0]);
        $connection->expects($this->never())->method('close');

        $this->expectExceptionMessage('was not held by this session at release');
        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static fn (): int => 7);
    }

    /**
     * Audit QU-L1: with DoctrineBundle `replicas:` the lock, the unit and the release run on the primary's session.
     */
    public function testAPrimaryReadReplicaConnectionIsPinnedToThePrimaryBeforeTheLock(): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 1], PrimaryReadReplicaConnection::class);
        // void on DBAL 4, bool on DBAL 3
        $returns = 'void' === (string) (new \ReflectionMethod(PrimaryReadReplicaConnection::class, 'ensureConnectedToPrimary'))->getReturnType() ? null : true;
        $connection->expects($this->once())->method('ensureConnectedToPrimary')->willReturnCallback(function () use ($returns): ?bool {
            $this->log[] = 'primary';

            return $returns;
        });

        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static fn (): int => 7);

        $this->assertSame(['primary', 'GET_LOCK', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'begin', 'commit', 'RELEASE_LOCK'], $this->statements());
    }

    public function testAUserLockThatCannotBeReleasedInsideTheHostsTransactionIsReported(): void
    {
        $connection = $this->connection(new MySQLPlatform(), true, ['GET_LOCK' => 1, 'RELEASE_LOCK' => new \RuntimeException('gone away')]);
        $connection->expects($this->never())->method('close');

        $this->expectExceptionMessage('gone away');
        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static fn (): int => 7);
    }

    public function testPostgreSqlPollsTheAdvisoryLockInsideItsTransaction(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform(), false, ['pg_try_advisory_xact_lock' => [false, false, true]]);

        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', function (): void {
            $this->log[] = 'unit';
        });

        $this->assertSame(['begin', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'pg_try_advisory_xact_lock', 'pg_try_advisory_xact_lock', 'pg_try_advisory_xact_lock', 'unit', 'commit'], $this->statements());
        $this->assertStringEndsWith(' ' . AttemptLockRunner::lockKey('login_attempts', '10.0.0.1'), $this->log[2]);
    }

    /**
     * A failed unit inside the host's transaction keeps nothing, even if the host catches the failure and commits.
     */
    public function testAFailedUnitInsideTheHostsTransactionRollsBackItsSavepoint(): void
    {
        $connection = $this->connection(new MySQLPlatform(), true, ['GET_LOCK' => 1]);

        try {
            (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static function (): never {
                throw new TransientStorageFailure(1);
            });
            $this->fail('The failure must reach the caller.');
        } catch (TransientStorageFailure) {
        }

        $this->assertSame(['GET_LOCK', 'savepoint', 'rollback savepoint', 'RELEASE_LOCK'], $this->statements());
    }

    public function testASavepointTheServerAlreadyDroppedReportsTheOriginalFailure(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform(), true, ['pg_try_advisory_xact_lock' => [true], 'ROLLBACK TO SAVEPOINT' => new \RuntimeException('no such savepoint')]);

        $this->expectExceptionMessage('deadlock');
        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static function (): never {
            throw new \RuntimeException('deadlock');
        });
    }

    public function testPostgreSqlInsideTheHostsTransactionKeepsTheLockInIt(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform(), true, ['pg_try_advisory_xact_lock' => [true]]);

        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', function (): void {
            $this->log[] = 'unit';
        });

        $this->assertSame(['savepoint', 'pg_try_advisory_xact_lock', 'unit', 'release savepoint'], $this->statements());
    }

    public function testAnAdvisoryLockNotGrantedInTimeFailsTransiently(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform(), false, ['pg_try_advisory_xact_lock' => array_fill(0, 200, false)]);
        $started = microtime(true);

        try {
            (new AttemptLockRunner(1))->run($connection, 'login_attempts', '10.0.0.1', function (): void {
                $this->log[] = 'unit';
            });
            $this->fail('The unit must not run without the lock.');
        } catch (TransientStorageFailure $failure) {
            $this->assertSame(AttemptLockRunner::RETRY_AFTER_SECONDS, $failure->retryAfterSeconds());
        }

        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $started);
        $this->assertNotContains('unit', $this->log);
        $this->assertContains('rollBack', $this->log, 'the runner ends its transaction');
    }

    public function testSqliteTakesTheDatabaseWriteLockFirst(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE "login attempts" (ip_address VARCHAR(45) NOT NULL)');

        $result = (new AttemptLockRunner())->run($connection, 'login attempts', '10.0.0.1', static function () use ($connection): int {
            $connection->insert('"login attempts"', ['ip_address' => '10.0.0.1']);

            return (int) $connection->fetchOne('SELECT COUNT(*) FROM "login attempts"');
        });

        $this->assertSame(1, $result);
        $this->assertFalse($connection->isTransactionActive());
    }

    public function testSqliteInsideTheHostsTransactionRunsInIt(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE login_attempts (ip_address VARCHAR(45) NOT NULL)');
        $connection->beginTransaction();

        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static function () use ($connection): void {
            $connection->insert('login_attempts', ['ip_address' => '10.0.0.1']);
        });

        $this->assertSame(1, $connection->getTransactionNestingLevel());
        $connection->rollBack();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM login_attempts'), 'the host decides');
    }

    public function testOtherPlatformsRunTheUnitInATransactionWithoutLock(): void
    {
        $connection = $this->connection(new OraclePlatform(), false, []);

        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', function (): void {
            $this->log[] = 'unit';
        });

        $this->assertSame(['begin', 'unit', 'commit'], $this->statements());
    }

    /**
     * 3.16 (REQ-RUNTIME-003 rule 6): every transient failure leaves the runner as the bundle's own type, so one subscriber
     * can answer it, whoever raised it first.
     */
    public function testALockNotGrantedIsTheBundlesFailure(): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 0]);

        $this->expectException(LoginThrottleStorageFailure::class);
        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static fn (): null => null);
    }

    public function testATransientFailureOfTheUnitBecomesTheBundlesFailureWithTheOriginalAsPrevious(): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 1]);
        $original = new TransientStorageFailure(5);

        try {
            (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static function () use ($original): never {
                throw $original;
            });
            $this->fail('The failure must reach the caller.');
        } catch (LoginThrottleStorageFailure $failure) {
            $this->assertSame($original, $failure->getPrevious());
            $this->assertSame(5, $failure->retryAfterSeconds());
        }
    }

    public function testADeadlockInsideTheHostsTransactionBecomesTheBundlesFailure(): void
    {
        $connection = $this->connection(new MySQLPlatform(), true, ['GET_LOCK' => 1]);
        $deadlock = new DeadlockException($this->createStub(Exception::class), null);

        try {
            (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static function () use ($deadlock): never {
                throw $deadlock;
            });
            $this->fail('The failure must reach the caller.');
        } catch (LoginThrottleStorageFailure $failure) {
            $this->assertSame($deadlock, $failure->getPrevious());
        }
    }

    public function testAFailureThatIsNotTransientIsNotAConflict(): void
    {
        $connection = $this->connection(new MySQLPlatform(), false, ['GET_LOCK' => 1]);

        $this->expectException(\LogicException::class);
        (new AttemptLockRunner())->run($connection, 'login_attempts', '10.0.0.1', static function (): never {
            throw new \LogicException('a bug');
        });
    }

    public function testLockNamesAndKeysArePerTableAndIpAddress(): void
    {
        $name = AttemptLockRunner::lockName('login_attempts', '2001:db8::1');
        $this->assertLessThanOrEqual(64, \strlen($name), 'MySQL user lock names are at most 64 characters');
        $this->assertSame($name, AttemptLockRunner::lockName('login_attempts', '2001:db8::1'));
        $this->assertNotSame($name, AttemptLockRunner::lockName('login_attempts', '2001:db8::2'));
        $this->assertNotSame($name, AttemptLockRunner::lockName('app_login_attempts', '2001:db8::1'));

        $key = AttemptLockRunner::lockKey('login_attempts', '10.0.0.1');
        $this->assertSame($key, AttemptLockRunner::lockKey('login_attempts', '10.0.0.1'));
        $this->assertNotSame($key, AttemptLockRunner::lockKey('login_attempts', '10.0.0.2'));
        $this->assertNotSame($key, AttemptLockRunner::lockKey('app_login_attempts', '10.0.0.1'));
    }

    public function testLockingReadsOnMySqlOnly(): void
    {
        $mysql = $this->connection(new MySQLPlatform(), false, []);
        $postgresql = $this->connection(new PostgreSQLPlatform(), false, []);

        $this->assertTrue((new AttemptLockRunner())->needsLockingReads($mysql));
        $this->assertFalse((new AttemptLockRunner())->needsLockingReads($postgresql));
        $this->assertTrue((new AttemptLockRunner(lockingReads: true))->needsLockingReads($postgresql));
        $this->assertFalse((new AttemptLockRunner(lockingReads: false))->needsLockingReads($mysql));
    }

    /**
     * A connection mock that logs the statements and answers fetchOne() by the SQL function it calls.
     *
     * @param array<string, mixed>     $answers SQL function => answer (a list: one per call; a Throwable: thrown); RELEASE_LOCK answers 1 unless given
     * @param class-string<Connection> $class   The connection class to mock
     */
    private function connection(AbstractPlatform $platform, bool $hostTransaction, array $answers, string $class = Connection::class): MockObject&Connection
    {
        $answers += ['RELEASE_LOCK' => 1];
        $connection = $this->createMock($class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $level = $hostTransaction ? 1 : 0;
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$level): bool {
            return $level > 0;
        });
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->method('beginTransaction')->willReturnCallback(function () use (&$level): void {
            ++$level;
            $this->log[] = 'begin';
        });
        $connection->method('commit')->willReturnCallback(function () use (&$level): void {
            --$level;
            $this->log[] = 'commit';
        });
        $connection->method('rollBack')->willReturnCallback(function () use (&$level): void {
            --$level;
            $this->log[] = 'rollBack';
        });
        $connection->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->log[] = $sql;

            return 0;
        });
        $connection->method('createSavepoint')->willReturnCallback(function (string $name): void {
            $this->assertStringStartsWith('nowo_login_throttle_unit_', $name);
            $this->log[] = 'savepoint';
        });
        $connection->method('releaseSavepoint')->willReturnCallback(function (): void {
            $this->log[] = 'release savepoint';
        });
        $connection->method('rollbackSavepoint')->willReturnCallback(function () use (&$answers): void {
            $this->log[] = 'rollback savepoint';
            if (($answers['ROLLBACK TO SAVEPOINT'] ?? null) instanceof \Throwable) {
                throw $answers['ROLLBACK TO SAVEPOINT'];
            }
        });
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $parameters = []) use (&$answers): mixed {
            $this->log[] = trim($sql . ' ' . implode(' ', array_map(strval(...), $parameters)));
            foreach ($answers as $function => &$answer) {
                if (!str_contains($sql, $function . '(')) {
                    continue;
                }
                $value = \is_array($answer) ? array_shift($answer) : $answer;
                if ($value instanceof \Throwable) {
                    throw $value;
                }

                return $value;
            }

            return null;
        });

        return $connection;
    }

    /**
     * The log with each statement reduced to its SQL function.
     *
     * @return list<string>
     */
    private function statements(): array
    {
        return array_map(static fn (string $entry): string => preg_match('/SELECT (\w+)\(/', $entry, $match) ? $match[1] : $entry, $this->log);
    }
}
