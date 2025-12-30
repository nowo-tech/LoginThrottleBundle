<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
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
    private ManagerRegistry|MockObject $registry;
    private EntityManagerInterface|MockObject $entityManager;
    private QueryBuilder|MockObject $queryBuilder;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);

        $this->registry
            ->method('getManagerForClass')
            ->with(LoginAttempt::class)
            ->willReturn($this->entityManager);

        // Create a partial mock of the repository that overrides createQueryBuilder
        $this->repository = $this->getMockBuilder(LoginAttemptRepository::class)
            ->setConstructorArgs([$this->registry])
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();

        // Mock createQueryBuilder to return our mocked QueryBuilder
        $this->repository
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);
    }

    public function testCountAttemptsByIp(): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn('5');

        $this->queryBuilder
            ->expects($this->once())
            ->method('select')
            ->with('COUNT(la.id)')
            ->willReturnSelf();

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
            ->willReturnCallback(function () use ($query) {
                return $query;
            });

        $result = $this->repository->countAttemptsByIp('192.168.1.1', 600);

        $this->assertSame(5, $result);
    }

    public function testCountAttemptsByUsername(): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $query->expects($this->once())
            ->method('getSingleScalarResult')
            ->willReturn('3');

        $this->queryBuilder
            ->expects($this->once())
            ->method('select')
            ->with('COUNT(la.id)')
            ->willReturnSelf();

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
            ->willReturnCallback(function () use ($query) {
                return $query;
            });

        $result = $this->repository->countAttemptsByUsername('test@example.com', 600);

        $this->assertSame(3, $result);
    }

    public function testGetAttemptsWithEmptyIp(): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();
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
            ->willReturnCallback(function () use ($query) {
                return $query;
            });

        $result = $this->repository->getAttempts('', 'test@example.com', 600);

        $this->assertIsArray($result);
    }

    public function testGetAttemptsWithNullUsername(): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();
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
            ->willReturnCallback(function () use ($query) {
                return $query;
            });

        $result = $this->repository->getAttempts('192.168.1.1', null, 600);

        $this->assertIsArray($result);
    }
}
