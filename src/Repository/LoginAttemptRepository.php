<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;

/**
 * Repository for LoginAttempt entity.
 *
 * @extends ServiceEntityRepository<LoginAttempt>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
class LoginAttemptRepository extends ServiceEntityRepository
{
    /**
     * Constructor.
     *
     * @param ManagerRegistry $registry The manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
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
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d seconds', $seconds)));

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
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d seconds', $seconds)));

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
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d seconds', $seconds)));

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
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     *
     * @return LoginAttempt The created attempt
     */
    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
    {
        $attempt = new LoginAttempt($ipAddress, $username);
        $this->getEntityManager()->persist($attempt);
        $this->getEntityManager()->flush();

        return $attempt;
    }

    /**
     * Clean up old login attempts (older than watch period).
     *
     * @param int $watchPeriodSeconds Period in seconds
     *
     * @return int Number of deleted records
     */
    public function cleanup(int $watchPeriodSeconds): int
    {
        $qb = $this->createQueryBuilder('la')
            ->delete()
            ->where('la.createdAt < :before')
            ->setParameter('before', new \DateTimeImmutable(sprintf('-%d seconds', $watchPeriodSeconds)));

        return $qb->getQuery()->execute();
    }

    /**
     * Get all attempts for a given IP and username.
     *
     * @param string      $ipAddress IP address (empty string to ignore IP filter)
     * @param string|null $username  Username (optional, null to ignore username filter)
     * @param int         $seconds   Time period in seconds
     *
     * @return LoginAttempt[]
     */
    public function getAttempts(string $ipAddress, ?string $username, int $seconds): array
    {
        $qb = $this->createQueryBuilder('la')
            ->where('la.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d seconds', $seconds)))
            ->orderBy('la.createdAt', 'DESC');

        if ('' !== $ipAddress) {
            $qb->andWhere('la.ipAddress = :ipAddress')
                ->setParameter('ipAddress', $ipAddress);
        }

        if (null !== $username) {
            $qb->andWhere('la.username = :username')
                ->setParameter('username', $username);
        }

        return $qb->getQuery()->getResult();
    }
}
