<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Tests for LoginAttemptRepository.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoginAttemptRepositoryTest extends TestCase
{
    private LoginAttemptRepository $repository;
    private MockObject&ManagerRegistry $registry;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&QueryBuilder $queryBuilder;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);

        $this->registry
            ->method('getManagerForClass')
            ->with(LoginAttempt::class)
            ->willReturn($this->entityManager);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = LoginAttempt::class;
        $this->entityManager
            ->method('getClassMetadata')
            ->with(LoginAttempt::class)
            ->willReturn($classMetadata);

        $this->entityManager
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();

        $this->repository = new LoginAttemptRepository($this->registry);
    }

    public function testAttemptWindowIsMeasuredOnTheInjectedClock(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-10-20 12:00:00');
            }
        };
        $repository = new LoginAttemptRepository($this->registry, $clock);

        $query = $this->createQueryMock();
        $query->method('getArrayResult')->willReturn([]);
        $this->queryBuilder->method('getQuery')->willReturn($query);
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $since = null;
        $this->queryBuilder->method('setParameter')->willReturnCallback(
            function (string $key, mixed $value) use (&$since): QueryBuilder {
                if ('since' === $key) {
                    $since = $value;
                }

                return $this->queryBuilder;
            },
        );

        $repository->countAttempts('10.0.0.1', null, 600);

        $this->assertInstanceOf(\DateTimeImmutable::class, $since);
        $this->assertSame('2026-10-20 11:50:00', $since->format('Y-m-d H:i:s'));
    }

    public function testCountAttemptsByIp(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getArrayResult')
            ->willReturn(array_merge(array_fill(0, 5, ['ipAddress' => '192.168.1.1']), [['ipAddress' => '192.168.1.1 ']]));

        $this->queryBuilder
            ->expects($this->once())
            ->method('where')
            ->with('la.ipAddress = :ipAddress')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('la.createdAt >= :since')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $result = $this->repository->countAttemptsByIp('192.168.1.1', 600);

        $this->assertSame(5, $result);
    }

    public function testCountAttemptsByUsername(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getArrayResult')
            ->willReturn([['username' => 'test@example.com'], ['username' => 'Test@example.com'], ['username' => 'test@example.com'], ['username' => 'tést@example.com'], ['username' => 'test@example.com']]);

        $this->queryBuilder
            ->expects($this->once())
            ->method('where')
            ->with('la.username = :username')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('la.createdAt >= :since')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $result = $this->repository->countAttemptsByUsername('test@example.com', 600);

        $this->assertSame(3, $result);
    }

    public function testGetAttemptsWithEmptyIp(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getResult')
            ->willReturn([]);

        $this->queryBuilder
            ->expects($this->once())
            ->method('where')
            ->with('la.createdAt >= :since')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('la.username = :username')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('orderBy')
            ->with('la.createdAt', \SortDirection::Descending)
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $result = $this->repository->getAttempts('', 'test@example.com', 600);

        $this->assertIsArray($result);
    }

    public function testGetAttemptsWithNullUsername(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getResult')
            ->willReturn([]);

        $this->queryBuilder
            ->expects($this->once())
            ->method('where')
            ->with('la.createdAt >= :since')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('andWhere')
            ->with('la.ipAddress = :ipAddress')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('orderBy')
            ->with('la.createdAt', \SortDirection::Descending)
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $result = $this->repository->getAttempts('192.168.1.1', null, 600);

        $this->assertIsArray($result);
    }

    public function testCountAttemptsWithoutUsername(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getArrayResult')
            ->willReturn([['ipAddress' => '10.0.0.1', 'username' => 'a'], ['ipAddress' => '10.0.0.1', 'username' => null]]);

        $this->queryBuilder->expects($this->once())->method('where')->with('la.ipAddress = :ipAddress')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('andWhere')->with('la.createdAt >= :since')->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))->method('setParameter')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('getQuery')->willReturn($query);

        $result = $this->repository->countAttempts('10.0.0.1', null, 120);

        $this->assertSame(2, $result);
    }

    public function testCountAttemptsWithUsername(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getArrayResult')
            ->willReturn(array_merge(
                array_fill(0, 4, ['ipAddress' => '10.0.0.1', 'username' => 'user@example.com']),
                [['ipAddress' => '10.0.0.1', 'username' => 'User@example.com'], ['ipAddress' => '10.0.0.1', 'username' => 'user@example.com ']],
                // a folding collation may also return another IP string (PAD SPACE): never counted
                [['ipAddress' => '10.0.0.1 ', 'username' => 'user@example.com']],
            ));

        $this->queryBuilder->expects($this->once())->method('where')->with('la.ipAddress = :ipAddress')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('andWhere')->with('la.createdAt >= :since')->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))->method('setParameter')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('getQuery')->willReturn($query);

        $result = $this->repository->countAttempts('10.0.0.1', 'user@example.com', 120);

        // 3.11.0: `User@` shares the folded skeleton; `user@ ` (trailing space) is another bucket
        $this->assertSame(5, $result);
    }

    public function testIsBlockedReturnsTrueWhenLimitReached(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getArrayResult')
            ->willReturn(array_fill(0, 3, ['ipAddress' => '127.0.0.1', 'username' => 'user@example.com']));

        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $this->assertTrue($this->repository->isBlocked('127.0.0.1', 'user@example.com', 3, 600));
    }

    public function testIsBlockedReturnsFalseWhenBelowLimit(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getArrayResult')
            ->willReturn([['ipAddress' => '127.0.0.1', 'username' => null]]);

        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $this->assertFalse($this->repository->isBlocked('127.0.0.1', null, 3, 600));
    }

    public function testRecordAttemptNeverPersistsOrFlushesTheManager(): void
    {
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');
        $this->entityManager->method('getConnection')->willThrowException(new \RuntimeException('insert attempted'));

        try {
            $this->repository->recordAttempt('192.168.1.10', 'demo@example.com');
            $this->fail('The attempt must be written through the connection.');
        } catch (\Throwable $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testCleanupDeletesInKeysetBatchesUntilAShortBatch(): void
    {
        $query = $this->createQueryMock();
        // Batch size 2: a full batch, then a short one ends the loop (no third SELECT).
        $query->expects($this->exactly(2))->method('getSingleColumnResult')->willReturn([1, 2], [5]);
        $query->expects($this->exactly(2))->method('execute')->willReturn(2, 1);
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('setMaxResults')->willReturnSelf();
        $this->queryBuilder->method('delete')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $this->assertSame(3, $this->repository->cleanup(3600, 2));
    }

    public function testCleanupStopsWhenNothingIsOld(): void
    {
        $query = $this->createQueryMock();
        $query->method('getSingleColumnResult')->willReturn([]);
        $query->expects($this->never())->method('execute');
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('setMaxResults')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $this->assertSame(0, $this->repository->cleanup(3600));
    }

    public function testCountOlderThan(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())->method('getSingleScalarResult')->willReturn('4');

        $this->queryBuilder->expects($this->once())->method('where')->with('la.createdAt < :before')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('setParameter')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('getQuery')->willReturn($query);

        $count = $this->repository->countOlderThan(3600);

        $this->assertSame(4, $count);
    }

    public function testClearAttemptsDeletesTheNewestExactRowAndUnnamesTheRest(): void
    {
        $query = $this->createQueryMock();
        // REQ-SEC-006: the folding SQL also returned look-alike rows (5, 6, 7); only 1, 2 and 3 are cleared. Newest
        // first: 1 is the attempt the successful request recorded itself and is deleted, 2 and 3 lose the username.
        $query->expects($this->once())->method('getArrayResult')->willReturn([
            ['id' => 1, 'ipAddress' => '192.168.1.1', 'username' => 'user@example.com'],
            ['id' => 5, 'ipAddress' => '192.168.1.1', 'username' => 'usér@example.com'],
            ['id' => 2, 'ipAddress' => '192.168.1.1', 'username' => 'user@example.com'],
            ['id' => 6, 'ipAddress' => '192.168.1.1', 'username' => 'User@example.com'],
            ['id' => 7, 'ipAddress' => '192.168.1.1', 'username' => 'user@example.com '],
            ['id' => 3, 'ipAddress' => '192.168.1.1', 'username' => 'user@example.com'],
        ]);
        $query->expects($this->exactly(2))->method('execute')->willReturn(1, 2);

        $this->queryBuilder->expects($this->once())->method('delete')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('update')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('set')->with('la.username', 'NULL')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('orderBy')->with('la.createdAt', \SortDirection::Descending)->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('addOrderBy')->with('la.id', \SortDirection::Descending)->willReturnSelf();
        $where = [];
        $this->queryBuilder->expects($this->exactly(3))->method('where')->willReturnCallback(function (string $predicate) use (&$where): QueryBuilder {
            $where[] = $predicate;

            return $this->queryBuilder;
        });
        $this->queryBuilder->expects($this->once())->method('andWhere')->with('la.username = :username')->willReturnSelf();
        $ids = [];
        $this->queryBuilder->expects($this->exactly(4))->method('setParameter')->willReturnCallback(function (string $key, mixed $value) use (&$ids): QueryBuilder {
            if ('ids' === $key) {
                $ids[] = $value;
            }

            return $this->queryBuilder;
        });
        $this->queryBuilder->expects($this->exactly(3))->method('getQuery')->willReturn($query);

        $cleared = $this->repository->clearAttempts('192.168.1.1', 'user@example.com');

        $this->assertSame(3, $cleared);
        $this->assertSame(['la.ipAddress = :ipAddress', 'la.id IN (:ids)', 'la.id IN (:ids)'], $where);
        $this->assertSame([[1], [2, 3]], $ids);
    }

    public function testClearAttemptsOnlyDeletesWhenTheBucketHoldsOneRow(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())->method('getArrayResult')->willReturn([
            ['id' => 9, 'ipAddress' => '192.168.1.1', 'username' => 'user@example.com'],
        ]);
        $query->expects($this->once())->method('execute')->willReturn(1);

        $this->queryBuilder->expects($this->once())->method('delete')->willReturnSelf();
        $this->queryBuilder->expects($this->never())->method('update');
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('addOrderBy')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $this->assertSame(1, $this->repository->clearAttempts('192.168.1.1', 'user@example.com'));
    }

    public function testClearAttemptsWithoutAnExactRowDeletesNothing(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())->method('getArrayResult')->willReturn([
            ['id' => 5, 'ipAddress' => '192.168.1.1', 'username' => 'alicé@example.com'],
        ]);
        $query->expects($this->never())->method('execute');
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('addOrderBy')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->expects($this->never())->method('delete');
        $this->queryBuilder->expects($this->never())->method('update');
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $this->assertSame(0, $this->repository->clearAttempts('192.168.1.1', 'alice@example.com'));
    }

    public function testClearAttemptsWithNullUsername(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())->method('getArrayResult')->willReturn([
            ['id' => 4, 'ipAddress' => '10.0.0.1', 'username' => null],
            ['id' => 8, 'ipAddress' => '10.0.0.1 ', 'username' => null],
        ]);
        $query->expects($this->once())->method('execute')->willReturn(1);

        $this->queryBuilder->expects($this->once())->method('delete')->willReturnSelf();
        $this->queryBuilder->expects($this->never())->method('update');
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('addOrderBy')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('andWhere')->with('la.username IS NULL')->willReturnSelf();
        $parameters = [];
        $this->queryBuilder->expects($this->exactly(2))->method('setParameter')->willReturnCallback(function (string $key, mixed $value) use (&$parameters): QueryBuilder {
            $parameters[$key] = $value;

            return $this->queryBuilder;
        });
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $deleted = $this->repository->clearAttempts('10.0.0.1', '');

        $this->assertSame(1, $deleted);
        $this->assertSame(['ipAddress' => '10.0.0.1', 'ids' => [4]], $parameters);
    }

    public function testEntityFreeReadsMapGetAttempts(): void
    {
        $newest = new LoginAttempt('192.168.1.1', 'alice');
        $oldest = new LoginAttempt('192.168.1.1', 'alice');
        (new \ReflectionProperty(LoginAttempt::class, 'createdAt'))->setValue($oldest, new \DateTimeImmutable('2026-11-10 09:00:00'));

        $query = $this->createQueryMock();
        // another bucket (a trailing space survives the skeleton), a cleared attempt (null username) and another IP
        $lookAlike = new LoginAttempt('192.168.1.1', 'alice ');
        $cleared = new LoginAttempt('192.168.1.1', null);
        $otherIp = new LoginAttempt('192.168.1.1 ', 'alice');
        $query->method('getResult')->willReturnOnConsecutiveCalls([$newest, $lookAlike, $cleared, $otherIp, $oldest], [$newest, $oldest, $lookAlike], []);
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $views = $this->repository->getAttemptViews('192.168.1.1', 'alice', 600);
        $this->assertCount(2, $views);
        $this->assertSame('alice', $views[0]->username);
        $this->assertSame($oldest->getCreatedAt(), $views[1]->createdAt);

        $this->assertSame($oldest->getCreatedAt(), $this->repository->findOldestAttemptAt('192.168.1.1', 'alice', 600));
        $this->assertNull($this->repository->findOldestAttemptAt('192.168.1.1', 'alice', 600));
    }

    /**
     * @return MockObject&Query
     */
    private function createQueryMock(): MockObject
    {
        return $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
