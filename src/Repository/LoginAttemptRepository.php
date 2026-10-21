<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Psr\Clock\ClockInterface;

/**
 * Repository for LoginAttempt entity.
 *
 * @extends ServiceEntityRepository<LoginAttempt>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoginAttemptRepository extends ServiceEntityRepository implements LoginAttemptRepositoryInterface
{
    /** Rows per DELETE statement in {@see cleanup()}. */
    public const CLEANUP_BATCH_SIZE = 1000;

    private readonly ManagerRegistry $managerRegistry;

    /**
     * Constructor.
     *
     * @param ManagerRegistry     $registry The manager registry
     * @param ClockInterface|null $clock    Time source for the attempt windows (system time when null; REQ-DI-001)
     */
    public function __construct(ManagerRegistry $registry, private readonly ?ClockInterface $clock = null)
    {
        $this->managerRegistry = $registry;
        parent::__construct($registry, LoginAttempt::class);
    }

    /**
     * Count failed login attempts for a given IP and username within a time period.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     * @param int         $seconds   Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttempts(string $ipAddress, ?string $username, int $seconds): int
    {
        $qb = $this->createQueryBuilder('la')
            ->select('COUNT(la.id)')
            ->where('la.ipAddress = :ipAddress')
            ->andWhere('la.createdAt >= :since')
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('since', $this->secondsAgo($seconds));

        if (null !== $username) {
            $qb->andWhere('la.username = :username')
                ->setParameter('username', $username);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Count failed login attempts by IP address only (ignoring username).
     *
     * @param string $ipAddress IP address
     * @param int    $seconds   Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttemptsByIp(string $ipAddress, int $seconds): int
    {
        $qb = $this->createQueryBuilder('la')
            ->select('COUNT(la.id)')
            ->where('la.ipAddress = :ipAddress')
            ->andWhere('la.createdAt >= :since')
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('since', $this->secondsAgo($seconds));

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Count failed login attempts by username/email only (ignoring IP).
     *
     * @param string $username Username/email
     * @param int    $seconds  Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttemptsByUsername(string $username, int $seconds): int
    {
        $qb = $this->createQueryBuilder('la')
            ->select('COUNT(la.id)')
            ->where('la.username = :username')
            ->andWhere('la.createdAt >= :since')
            ->setParameter('username', $username)
            ->setParameter('since', $this->secondsAgo($seconds));

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

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
    public function isBlocked(string $ipAddress, ?string $username, int $maxAttempts, int $timeoutSeconds): bool
    {
        $count = $this->countAttempts($ipAddress, $username, $timeoutSeconds);

        return $count >= $maxAttempts;
    }

    /**
     * Record a failed login attempt.
     *
     * The attempt is detached after the flush so a long-lived EntityManager (worker mode without
     * kernel reset) does not accumulate one entity per login POST. A manager closed by a failed
     * flush is reset before the exception is rethrown, so the next request can record again.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     *
     * @return LoginAttempt The created attempt (detached)
     */
    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
    {
        $entityManager = $this->resolveWritableEntityManager();
        $attempt = new LoginAttempt($ipAddress, $username);

        try {
            $entityManager->persist($attempt);
            $entityManager->flush();
        } catch (\Throwable $exception) {
            $this->resetClosedEntityManager($entityManager);

            throw $exception;
        } finally {
            if ($entityManager->isOpen() && $entityManager->contains($attempt)) {
                $entityManager->detach($attempt);
            }
        }

        return $attempt;
    }

    /**
     * Delete login attempts for a given IP and username (e.g. after successful login).
     *
     * When username is null or empty, only rows with a null username are removed.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     *
     * @return int Number of deleted records
     */
    public function clearAttempts(string $ipAddress, ?string $username): int
    {
        $qb = $this->createQueryBuilder('la')
            ->delete()
            ->where('la.ipAddress = :ipAddress')
            ->setParameter('ipAddress', $ipAddress);

        if (null === $username || '' === $username) {
            $qb->andWhere('la.username IS NULL');
        } else {
            $qb->andWhere('la.username = :username')
                ->setParameter('username', $username);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Clean up old login attempts (older than watch period).
     *
     * Deletes in keyset batches (`id > :last ORDER BY id LIMIT n`, one short `DELETE … WHERE id IN (…)`
     * per batch) instead of one unbounded DELETE that locks the table on busy instances.
     *
     * @param int $watchPeriodSeconds Period in seconds
     * @param int $batchSize          Rows per DELETE statement
     *
     * @return int Number of deleted records
     */
    public function cleanup(int $watchPeriodSeconds, int $batchSize = self::CLEANUP_BATCH_SIZE): int
    {
        $before = $this->secondsAgo($watchPeriodSeconds);
        $batchSize = max(1, $batchSize);
        $deleted = 0;
        $last = 0;
        do {
            /** @var list<int|string> $ids */
            $ids = $this->createQueryBuilder('la')
                ->select('la.id')
                ->where('la.createdAt < :before')
                ->andWhere('la.id > :last')
                ->setParameter('before', $before)
                ->setParameter('last', $last)
                ->orderBy('la.id', 'ASC')
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getSingleColumnResult();
            if ([] === $ids) {
                break;
            }
            $deleted += (int) $this->createQueryBuilder('la')
                ->delete()
                ->where('la.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute();
            $last = (int) $ids[\count($ids) - 1];
        } while (\count($ids) === $batchSize);

        return $deleted;
    }

    /**
     * Count login attempts older than the watch period (same filter as {@see cleanup()}).
     *
     * @param int $watchPeriodSeconds Period in seconds
     *
     * @return int Number of matching records
     */
    public function countOlderThan(int $watchPeriodSeconds): int
    {
        $qb = $this->createQueryBuilder('la')
            ->select('COUNT(la.id)')
            ->where('la.createdAt < :before')
            ->setParameter('before', $this->secondsAgo($watchPeriodSeconds));

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get all attempts for a given IP and username.
     *
     * @param string      $ipAddress IP address (empty string to ignore IP filter)
     * @param string|null $username  Username (optional, null to ignore username filter)
     * @param int         $seconds   Time period in seconds
     *
     * @return list<LoginAttempt>
     */
    public function getAttempts(string $ipAddress, ?string $username, int $seconds): array
    {
        $qb = $this->createQueryBuilder('la')
            ->where('la.createdAt >= :since')
            ->setParameter('since', $this->secondsAgo($seconds))
            ->orderBy('la.createdAt', \SortDirection::Descending);

        if ('' !== $ipAddress) {
            $qb->andWhere('la.ipAddress = :ipAddress')
                ->setParameter('ipAddress', $ipAddress);
        }

        if (null !== $username) {
            $qb->andWhere('la.username = :username')
                ->setParameter('username', $username);
        }

        /** @var list<LoginAttempt> $result */
        $result = $qb->getQuery()->getResult();

        $entityManager = $this->getEntityManager();
        foreach ($result as $attempt) {
            if ($entityManager->contains($attempt)) {
                $entityManager->detach($attempt);
            }
        }

        return $result;
    }

    /**
     * Build every DQL query through {@see getEntityManager()} so DoctrineBundle's
     * {@see ServiceEntityRepository} proxy does not keep using an EntityRepository bound to a
     * closed manager after {@see ManagerRegistry::resetManager()} (ORM 3 / worker mode).
     */
    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select($alias)
            ->from(LoginAttempt::class, $alias, $indexBy);
    }

    /**
     * Always resolve the manager from {@see ManagerRegistry} so a worker that reset a closed
     * EntityManager does not keep using the instance cached by {@see ServiceEntityRepository}.
     */
    protected function getEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(LoginAttempt::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('Expected an ORM EntityManager for %s, got %s.', LoginAttempt::class, $entityManager instanceof ObjectManager ? $entityManager::class : 'null'));
        }

        return $this->resetClosedEntityManager($entityManager) ?? $entityManager;
    }

    private function resolveWritableEntityManager(): EntityManagerInterface
    {
        return $this->getEntityManager();
    }

    private function resetClosedEntityManager(EntityManagerInterface $entityManager): ?EntityManagerInterface
    {
        if ($entityManager->isOpen()) {
            return null;
        }

        foreach (array_keys($this->managerRegistry->getManagerNames()) as $name) {
            if ($this->managerRegistry->getManager($name) !== $entityManager) {
                continue;
            }

            $reset = $this->managerRegistry->resetManager($name);

            return $reset instanceof EntityManagerInterface ? $reset : null;
        }

        return null;
    }

    /**
     * Point in time $seconds before now, on the injected clock (system time when none is injected).
     */
    private function secondsAgo(int $seconds): \DateTimeImmutable
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->sub(new \DateInterval(\sprintf('PT%dS', $seconds)));
    }
}
