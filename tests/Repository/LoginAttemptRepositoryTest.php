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

    public function testCountAttemptsByIp(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn('5');

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
            ->method('getSingleScalarResult')
            ->willReturn('3');

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
            ->with('la.createdAt', 'DESC')
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
            ->with('la.createdAt', 'DESC')
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
            ->method('getSingleScalarResult')
            ->willReturn('2');

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
            ->method('getSingleScalarResult')
            ->willReturn('4');

        $this->queryBuilder->expects($this->once())->method('where')->with('la.ipAddress = :ipAddress')->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(2))->method('andWhere')->willReturnSelf();
        $this->queryBuilder->expects($this->exactly(3))->method('setParameter')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('getQuery')->willReturn($query);

        $result = $this->repository->countAttempts('10.0.0.1', 'user@example.com', 120);

        $this->assertSame(4, $result);
    }

    public function testIsBlockedReturnsTrueWhenLimitReached(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn('3');

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
            ->method('getSingleScalarResult')
            ->willReturn('1');

        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($query);

        $this->assertFalse($this->repository->isBlocked('127.0.0.1', null, 3, 600));
    }

    public function testRecordAttemptPersistsAndFlushes(): void
    {
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(LoginAttempt::class));
        $this->entityManager->expects($this->once())
            ->method('flush');

        $attempt = $this->repository->recordAttempt('192.168.1.10', 'demo@example.com');

        $this->assertInstanceOf(LoginAttempt::class, $attempt);
        $this->assertSame('192.168.1.10', $attempt->getIpAddress());
        $this->assertSame('demo@example.com', $attempt->getUsername());
    }

    public function testCleanupExecutesDeleteQuery(): void
    {
        $query = $this->createQueryMock();
        $query->expects($this->once())->method('execute')->willReturn(7);

        $this->queryBuilder->expects($this->once())->method('delete')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('where')->with('la.createdAt < :before')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('setParameter')->willReturnSelf();
        $this->queryBuilder->expects($this->once())->method('getQuery')->willReturn($query);

        $deleted = $this->repository->cleanup(3600);

        $this->assertSame(7, $deleted);
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
