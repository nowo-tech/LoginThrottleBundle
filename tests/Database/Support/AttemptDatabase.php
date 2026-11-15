<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Database\Support;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Transaction\AttemptLockRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The real-server side of the load-readiness tests (3.15, Beacon spec 239): one entity manager per process on
 * LOGIN_THROTTLE_TEST_DATABASE_URL, the bundle's repository and limiter on it. Only public API of the bundle that
 * already existed in 3.14.0 is used, so the same tests run against 3.14.0 (where the races they show fail).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class AttemptDatabase
{
    public const URL_VARIABLE = 'LOGIN_THROTTLE_TEST_DATABASE_URL';

    public static function url(): ?string
    {
        $url = getenv(self::URL_VARIABLE);

        return \is_string($url) && '' !== $url ? $url : null;
    }

    public static function entityManager(string $url, bool $replicas = false): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 3) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400 && method_exists($configuration, 'enableNativeLazyObjects')) {
            $configuration->enableNativeLazyObjects(true);
        }
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse($url);

        if ($replicas) {
            // DoctrineBundle `replicas:` with the replica on the same server: a replica session is another session
            $parameters = ['wrapperClass' => PrimaryReadReplicaConnection::class, 'driver' => $parameters['driver'] ?? 'pdo_mysql', 'primary' => $parameters, 'replica' => [$parameters], 'keepReplica' => true];
        }

        return new EntityManager(DriverManager::getConnection($parameters, $configuration), $configuration);
    }

    /**
     * Drops and creates the attempt table (and the host's note table the transaction tests write to).
     */
    public static function createSchema(EntityManager $entityManager): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS login_attempts');
        $connection->executeStatement('DROP TABLE IF EXISTS host_load_note');
        if ($connection->getDatabasePlatform()->supportsSequences()) {
            // the IDENTITY of DBAL 3 on PostgreSQL is a separate sequence
            $connection->executeStatement('DROP SEQUENCE IF EXISTS login_attempts_id_seq');
        }
        $connection->executeStatement('CREATE TABLE host_load_note (id INT NOT NULL PRIMARY KEY)');
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(LoginAttempt::class)]);
    }

    /**
     * The bundle's repository; since 3.15 with a 10 s lock wait, so a worker the tests hold back on purpose never times
     * out (3.14.0 ignores the extra argument).
     */
    public static function repository(EntityManager $entityManager, ?ClockInterface $clock = null): LoginAttemptRepository
    {
        return new LoginAttemptRepository(self::registry($entityManager), $clock, class_exists(AttemptLockRunner::class) ? new AttemptLockRunner(10) : null);
    }

    public static function limiter(LoginAttemptRepository $repository, int $maxAttempts, int $maxAttemptsPerIp, ?int $maxAttemptsWithoutUsername = null): DatabaseRateLimiter
    {
        return new DatabaseRateLimiter($repository, $maxAttempts, 600, $maxAttemptsPerIp, null, false, $maxAttemptsWithoutUsername);
    }

    public static function request(string $ipAddress, ?string $username): Request
    {
        return Request::create('/login', 'POST', null === $username ? [] : ['_username' => $username], [], [], ['REMOTE_ADDR' => $ipAddress]);
    }

    public static function registry(EntityManager $entityManager): AbstractManagerRegistry
    {
        return new class($entityManager) extends AbstractManagerRegistry {
            public function __construct(private readonly EntityManager $entityManager)
            {
                parent::__construct('load', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default', Proxy::class);
            }

            protected function getService(string $name): object
            {
                return 'connection' === $name ? $this->entityManager->getConnection() : $this->entityManager;
            }

            protected function resetService(string $name): void
            {
            }

            public function getAliasNamespace(string $alias): string
            {
                return $alias;
            }
        };
    }
}
