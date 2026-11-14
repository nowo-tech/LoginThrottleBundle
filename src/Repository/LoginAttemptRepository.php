<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\ObjectManager;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Throttle\UsernameNormalizer;
use Psr\Clock\ClockInterface;

/**
 * Repository for LoginAttempt entity.
 *
 * @extends ServiceEntityRepository<LoginAttempt>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoginAttemptRepository extends ServiceEntityRepository implements LoginAttemptRecorderInterface, LoginAttemptRepositoryInterface, LoginAttemptStoreInterface, LoginAttemptViewRepositoryInterface, LoginAttemptWithoutUsernameReaderInterface
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
     * With a username, every attempt of the IP in the window is read (the per-IP cap bounds them) and the attempts
     * whose username has the same folded skeleton are counted ({@see UsernameNormalizer::skeleton()}: compatibility
     * decomposition, accents and case folded), so `bób`, `ｂob` or `BOB` never get a fresh budget against `bob`
     * (3.11.0). Without a username, every attempt of the IP counts.
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
            ->select('la.ipAddress AS ipAddress', 'la.username AS username')
            ->where('la.ipAddress = :ipAddress')
            ->andWhere('la.createdAt >= :since')
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('since', $this->secondsAgo($seconds));

        $skeleton = null === $username ? null : UsernameNormalizer::skeleton($username);
        $count = 0;
        foreach ($this->rows($qb) as $row) {
            if (($row['ipAddress'] ?? null) !== $ipAddress) {
                continue;
            }
            $stored = $row['username'] ?? null;
            if (null === $skeleton || (\is_string($stored) && UsernameNormalizer::skeleton($stored) === $skeleton)) {
                ++$count;
            }
        }

        return $count;
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
            ->select('la.ipAddress AS ipAddress')
            ->where('la.ipAddress = :ipAddress')
            ->andWhere('la.createdAt >= :since')
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('since', $this->secondsAgo($seconds));

        return $this->countExact($qb, $ipAddress, null);
    }

    /**
     * Count the attempts of exactly this IP without a username within the window (3.13.0).
     *
     * @param string $ipAddress IP address
     * @param int    $seconds   Time window in seconds
     *
     * @return int Number of attempts
     */
    public function countAttemptsWithoutUsername(string $ipAddress, int $seconds): int
    {
        return \count($this->attemptTimesWithoutUsername($ipAddress, $seconds));
    }

    /**
     * Time of the oldest attempt of exactly this IP without a username within the window (3.13.0).
     *
     * @param string $ipAddress IP address
     * @param int    $seconds   Time window in seconds
     *
     * @return \DateTimeImmutable|null The oldest attempt time, or null without such attempts
     */
    public function findOldestAttemptWithoutUsernameAt(string $ipAddress, int $seconds): ?\DateTimeImmutable
    {
        $times = $this->attemptTimesWithoutUsername($ipAddress, $seconds);

        return [] === $times ? null : min($times);
    }

    /**
     * Count failed login attempts by username/email only (ignoring IP).
     *
     * Exact (`===`): across IPs there is no per-IP cap bounding a skeleton scan. The limiter does not use it.
     *
     * @param string $username Username/email
     * @param int    $seconds  Time period in seconds
     *
     * @return int Number of attempts
     */
    public function countAttemptsByUsername(string $username, int $seconds): int
    {
        $qb = $this->createQueryBuilder('la')
            ->select('la.username AS username')
            ->where('la.username = :username')
            ->andWhere('la.createdAt >= :since')
            ->setParameter('username', $username)
            ->setParameter('since', $this->secondsAgo($seconds));

        return $this->countExact($qb, null, $username);
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
     * Written with one DBAL `INSERT` on the connection of the attempt's EntityManager, never `persist()` + `flush()`:
     * in a standard install that manager is the host's default one, and a flush on a failed login would also commit
     * whatever the host left pending in its unit of work. Inside a transaction the host left open, the row is written in a savepoint of that transaction.
     * The attempt never enters the identity map (worker safe) and a failed insert does not close the manager.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     *
     * @return LoginAttempt The created attempt (not managed, its id set)
     *
     * @deprecated since 3.12, use {@see recordAttemptView()} ({@see LoginAttemptRecorderInterface}); removed in 4.0
     */
    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
    {
        $entityManager = $this->getEntityManager();
        $metadata = $entityManager->getClassMetadata(LoginAttempt::class);
        $idGenerator = $metadata->idGenerator;
        $attempt = new LoginAttempt($ipAddress, $username);

        $id = null;
        $data = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            $isIdentifier = $metadata->isIdentifier($field);
            if ($isIdentifier && $idGenerator->isPostInsertGenerator()) {
                continue;
            }

            $column = $metadata->getColumnName($field);
            // SEQUENCE (or another pre-insert generator) chosen by the host's identity_generation_preferences
            $value = $isIdentifier ? $id = $idGenerator->generateId($entityManager, $attempt) : $metadata->getFieldValue($attempt, $field);
            $data[$column] = $value;
            $types[$column] = $metadata->getTypeOfField($field) ?? Types::STRING;
        }

        $connection = $entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            // A savepoint: a failed insert (e.g. an overlong username) rolls back only itself instead of aborting the
            // host's whole transaction on PostgreSQL (SQLSTATE 25P02), which the ORM flush (a nested transaction) never
            // did. Explicit savepoint calls, because DBAL 3 nests transactional() without a savepoint unless enabled.
            // a name unique per call: on MySQL / MariaDB a SAVEPOINT with an existing name replaces it, so a fixed name
            // could replace a host savepoint that happens to share it
            $savepoint = 'nowo_login_throttle_insert_' . bin2hex(random_bytes(6));
            $connection->createSavepoint($savepoint);

            try {
                $connection->insert($metadata->getTableName(), $data, $types);
                // before RELEASE SAVEPOINT: on MySQL / MariaDB every statement resets the last insert id
                $id ??= $idGenerator->generateId($entityManager, $attempt);
            } catch (\Throwable $exception) {
                try {
                    $connection->rollbackSavepoint($savepoint);
                } catch (\Throwable) {
                    // a deadlock or lock wait timeout may already have rolled back the whole transaction (and the
                    // savepoint with it): the original error is the one to report
                }

                throw $exception;
            }
            $connection->releaseSavepoint($savepoint);
        } else {
            $connection->insert($metadata->getTableName(), $data, $types);
        }

        // IDENTITY: the generator reads the connection's last insert id, as the ORM does after its own insert
        $id ??= $idGenerator->generateId($entityManager, $attempt);
        // @igor-ignore - sets the id of the new, unmanaged attempt; the metadata itself is not changed
        $metadata->setIdentifierValues($attempt, [$metadata->getSingleIdentifierFieldName() => $id]);

        return $attempt;
    }

    /**
     * Clear login attempts for a given IP and username (e.g. after successful login).
     *
     * When username is null or empty, only rows with a null username are removed.
     *
     * With a username, selects first and keeps the rows whose IP and username are byte-identical (`===`,
     * REQ-SEC-006): on a folding MySQL / MariaDB collation `alicé@` = `alice@` = `Alice@ `, so a delete by username
     * alone let a successful login on a look-alike account wipe another account's failed attempts.
     *
     * Of those rows (3.11.0), the newest is deleted and the older ones keep the row but lose the username (set to
     * NULL):
     * - the newest is the attempt the successful request itself just recorded (Symfony's limiter consumes a token on
     *   every attempt, successful ones included), so deleting it keeps a successful login from spending the per-IP
     *   budget — otherwise a shared IP (office NAT, CGNAT) would lock itself out after `max_count_attempts_per_ip`
     *   logins;
     * - the older ones are real failures. They leave the username's bucket, so the next failure starts a fresh
     *   window, and still count for the per-IP cap until they expire, which bounds any confusion about which bucket a
     *   successful login clears.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (optional)
     *
     * @return int Number of cleared records (the deleted one included)
     */
    public function clearAttempts(string $ipAddress, ?string $username): int
    {
        $username = '' === $username ? null : $username;
        $qb = $this->createQueryBuilder('la')
            ->select('la.id AS id', 'la.ipAddress AS ipAddress', 'la.username AS username')
            ->where('la.ipAddress = :ipAddress')
            ->orderBy('la.createdAt', \SortDirection::Descending)
            ->addOrderBy('la.id', \SortDirection::Descending)
            ->setParameter('ipAddress', $ipAddress);

        if (null === $username) {
            $qb->andWhere('la.username IS NULL');
        } else {
            $qb->andWhere('la.username = :username')
                ->setParameter('username', $username);
        }

        $ids = [];
        foreach ($this->rows($qb) as $row) {
            if (self::isExact($row, $ipAddress, $username, true)) {
                $ids[] = $row['id'];
            }
        }

        if ([] === $ids) {
            return 0;
        }

        // newest first: the row the successful request recorded itself goes, the older failures only lose the username
        $newest = [array_shift($ids)];

        return $this->deleteByIds($newest) + (null === $username ? $this->deleteByIds($ids) : $this->forgetUsernameOf($ids));
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
     *
     * @deprecated since 3.12, use {@see getAttemptViews()} ({@see LoginAttemptViewRepositoryInterface}); removed in 4.0
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

        // with an IP, the bucket is the username's folded skeleton (as in countAttempts()); across IPs, exact
        $skeleton = '' !== $ipAddress && null !== $username ? UsernameNormalizer::skeleton($username) : null;
        if (null !== $username && null === $skeleton) {
            $qb->andWhere('la.username = :username')
                ->setParameter('username', $username);
        }

        /** @var list<LoginAttempt> $found */
        $found = $qb->getQuery()->getResult();

        $entityManager = $this->getEntityManager();
        $result = [];
        foreach ($found as $attempt) {
            if ($entityManager->contains($attempt)) {
                $entityManager->detach($attempt);
            }
            // REQ-SEC-006: the SQL filter may fold (case, accents, trailing spaces); keep the bucket's rows only
            if ('' !== $ipAddress && $attempt->getIpAddress() !== $ipAddress) {
                continue;
            }
            $stored = $attempt->getUsername();
            if (null !== $skeleton ? null !== $stored && UsernameNormalizer::skeleton($stored) === $skeleton : null === $username || $stored === $username) {
                $result[] = $attempt;
            }
        }

        return $result;
    }

    /**
     * Attempts for a given IP and username as read-only views, newest first (3.9).
     *
     * @param string      $ipAddress IP address (empty string to ignore the IP filter)
     * @param string|null $username  Username (null to ignore the username filter)
     * @param int         $seconds   Time window in seconds
     *
     * @return list<LoginAttemptView>
     */
    public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array
    {
        return array_map(LoginAttemptView::fromEntity(...), $this->getAttempts($ipAddress, $username, $seconds));
    }

    /**
     * Records a failed login attempt and returns its read-only view (3.12): the entity-free twin of
     * {@see recordAttempt()}, so the limiter never handles the Doctrine entity on the write path.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (null for the IP-only bucket)
     *
     * @return LoginAttemptView The recorded attempt, its id set
     */
    public function recordAttemptView(string $ipAddress, ?string $username): LoginAttemptView
    {
        return LoginAttemptView::fromEntity($this->recordAttempt($ipAddress, $username));
    }

    /**
     * Time of the oldest attempt for a given IP and username within the window (3.9).
     *
     * @param string      $ipAddress IP address (empty string to ignore the IP filter)
     * @param string|null $username  Username (null to ignore the username filter)
     * @param int         $seconds   Time window in seconds
     *
     * @return \DateTimeImmutable|null The oldest attempt time, or null without attempts
     */
    public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): ?\DateTimeImmutable
    {
        $attempts = $this->getAttempts($ipAddress, $username, $seconds);

        return [] === $attempts ? null : $attempts[array_key_last($attempts)]->getCreatedAt();
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

    /**
     * Resets a closed manager only when it maps the bundle's entities alone (a dedicated manager). A closed manager
     * shared with the host is left for the host to reset: DQL reads and DBAL writes still work through it.
     */
    private function resetClosedEntityManager(EntityManagerInterface $entityManager): ?EntityManagerInterface
    {
        if ($entityManager->isOpen() || !$this->isDedicatedManager($entityManager)) {
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

    /** True when every class the manager maps belongs to this bundle. */
    private function isDedicatedManager(EntityManagerInterface $entityManager): bool
    {
        $driver = $entityManager->getConfiguration()->getMetadataDriverImpl();
        if (!$driver instanceof MappingDriver) {
            return false;
        }

        foreach ($driver->getAllClassNames() as $className) {
            if (!str_starts_with($className, 'Nowo\\LoginThrottleBundle\\Entity\\')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deletes the attempts of $ids (by primary key, in batches).
     *
     * @param list<mixed> $ids
     */
    private function deleteByIds(array $ids): int
    {
        return $this->byIds($ids, static fn (QueryBuilder $qb): QueryBuilder => $qb->delete());
    }

    /**
     * Takes the username off the attempts of $ids (by primary key, in batches): the row leaves its username's bucket
     * and keeps counting for the IP.
     *
     * @param list<mixed> $ids
     */
    private function forgetUsernameOf(array $ids): int
    {
        return $this->byIds($ids, static fn (QueryBuilder $qb): QueryBuilder => $qb->update()->set('la.username', 'NULL'));
    }

    /**
     * @param list<mixed>                          $ids
     * @param callable(QueryBuilder): QueryBuilder $statement
     */
    private function byIds(array $ids, callable $statement): int
    {
        $changed = 0;
        foreach (array_chunk($ids, self::CLEANUP_BATCH_SIZE) as $chunk) {
            $changed += (int) $statement($this->createQueryBuilder('la'))
                ->where('la.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->execute();
        }

        return $changed;
    }

    /**
     * Rows of $qb (scalar columns selected by alias) whose IP and username are byte-identical to the requested ones.
     *
     * The count happens in PHP after the SQL filter (REQ-SEC-006): a MySQL / MariaDB column on a folding collation
     * (the default before the `ALTER` of UPGRADING 3.11.0) also returns look-alike rows (`Alice`, `alicé`,
     * `alice `), which must neither count for nor against another username. No LIMIT is involved, so no exact row is
     * ever hidden by a look-alike.
     *
     * @param string|null $ipAddress Required IP (null: the query does not select it)
     * @param string|null $username  Required username (null: the query does not filter on it)
     */
    private function countExact(QueryBuilder $qb, ?string $ipAddress, ?string $username): int
    {
        $count = 0;
        foreach ($this->rows($qb) as $row) {
            if (self::isExact($row, $ipAddress, $username, false)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Times of the attempts of exactly this IP whose username is NULL, within the window.
     *
     * @return list<\DateTimeImmutable>
     */
    private function attemptTimesWithoutUsername(string $ipAddress, int $seconds): array
    {
        $qb = $this->createQueryBuilder('la')
            ->select('la.ipAddress AS ipAddress', 'la.username AS username', 'la.createdAt AS createdAt')
            ->where('la.ipAddress = :ipAddress')
            ->andWhere('la.username IS NULL')
            ->andWhere('la.createdAt >= :since')
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('since', $this->secondsAgo($seconds));

        $times = [];
        foreach ($this->rows($qb) as $row) {
            // byte-exact IP, as the per-IP cap counts it (a PAD SPACE collation also returns `10.0.0.1 `)
            if (self::isExact($row, $ipAddress, null, true) && ($row['createdAt'] ?? null) instanceof \DateTimeImmutable) {
                $times[] = $row['createdAt'];
            }
        }

        return $times;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(QueryBuilder $qb): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * Whether a row read back is the requested IP / username byte for byte (`===`).
     *
     * @param array<string, mixed> $row         Row with the selected `ipAddress` / `username` aliases
     * @param string|null          $ipAddress   Required IP (null: not checked)
     * @param string|null          $username    Required username (null: not checked, unless $nullIsValue)
     * @param bool                 $nullIsValue A null $username requires a null username in the row
     */
    private static function isExact(array $row, ?string $ipAddress, ?string $username, bool $nullIsValue): bool
    {
        if (null !== $ipAddress && ($row['ipAddress'] ?? null) !== $ipAddress) {
            return false;
        }

        if (null === $username && !$nullIsValue) {
            return true;
        }

        return ($row['username'] ?? null) === $username;
    }

    /**
     * Point in time $seconds before now, on the injected clock (system time when none is injected).
     */
    private function secondsAgo(int $seconds): \DateTimeImmutable
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->sub(new \DateInterval(\sprintf('PT%dS', $seconds)));
    }
}
