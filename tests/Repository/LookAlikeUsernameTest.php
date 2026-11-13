<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Doctrine\ExactCollationListener;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * REQ-SEC-006 (3.10.2): look-alike usernames (`Bob`, `bób`, `bob `, `bob\n`, NBSP, ZWSP) never count for, count
 * against, or clear the attempts of `bob`.
 *
 * Runs on SQLite with `COLLATE NOCASE` columns always, and on MySQL / MariaDB when LOGIN_THROTTLE_TEST_MYSQL_URL /
 * LOGIN_THROTTLE_TEST_MARIADB_URL point at a throwaway database (the tables are dropped first). The "legacy" variants
 * keep the server's default folding collation (a table created before 3.10.2, without the ALTER of UPGRADING): the
 * repository's `===` re-check alone must keep the counters exact.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LookAlikeUsernameTest extends TestCase
{
    private const IP = '10.0.0.1';

    private const OWNER = 'bob@example.com';

    /** @var list<string> */
    private const LOOK_ALIKES = [
        'Bob@example.com',
        'bób@example.com',
        'bob@example.com ',
        "bob@example.com\n",
        "bob@example.com\u{00A0}",
        "bob@example.com\u{200B}",
    ];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function platforms(): iterable
    {
        yield 'sqlite nocase' => ['sqlite', false];
        yield 'mysql legacy collation' => ['LOGIN_THROTTLE_TEST_MYSQL_URL', false];
        yield 'mysql exact collation' => ['LOGIN_THROTTLE_TEST_MYSQL_URL', true];
        yield 'mariadb legacy collation' => ['LOGIN_THROTTLE_TEST_MARIADB_URL', false];
        yield 'mariadb exact collation' => ['LOGIN_THROTTLE_TEST_MARIADB_URL', true];
    }

    #[DataProvider('platforms')]
    public function testLookAlikesNeverCountForTheOwner(string $source, bool $exactCollation): void
    {
        $repository = $this->seededRepository($source, $exactCollation);

        $this->assertSame(3, $repository->countAttempts(self::IP, self::OWNER, 600));
        // the fourth row of the owner comes from another IP string
        $this->assertSame(4, $repository->countAttemptsByUsername(self::OWNER, 600));
        $this->assertTrue($repository->isBlocked(self::IP, self::OWNER, 3, 600));
        $this->assertFalse($repository->isBlocked(self::IP, self::OWNER, 4, 600));
        $this->assertCount(3, $repository->getAttempts(self::IP, self::OWNER, 600));
        $this->assertCount(4, $repository->getAttempts('', self::OWNER, 600));
        foreach (self::LOOK_ALIKES as $lookAlike) {
            $this->assertSame(1, $repository->countAttempts(self::IP, $lookAlike, 600), json_encode($lookAlike, \JSON_THROW_ON_ERROR));
            $this->assertSame(1, $repository->countAttemptsByUsername($lookAlike, 600));
            $views = $repository->getAttemptViews(self::IP, $lookAlike, 600);
            $this->assertCount(1, $views);
            $this->assertSame($lookAlike, $views[0]->username);
        }
        $this->assertSame(3 + \count(self::LOOK_ALIKES), $repository->countAttemptsByIp(self::IP, 600));
        $this->assertSame(1, $repository->countAttemptsByIp(self::IP . ' ', 600));
    }

    /**
     * F2: a successful login on a look-alike account clears only that account's own attempts, never the owner's.
     */
    #[DataProvider('platforms')]
    public function testClearingALookAlikeKeepsTheOwnersAttempts(string $source, bool $exactCollation): void
    {
        $repository = $this->seededRepository($source, $exactCollation);

        foreach (self::LOOK_ALIKES as $lookAlike) {
            $this->assertSame(1, $repository->clearAttempts(self::IP, $lookAlike), json_encode($lookAlike, \JSON_THROW_ON_ERROR));
        }
        $this->assertSame(0, $repository->clearAttempts(self::IP, 'bóB@example.com'));

        $this->assertSame(3, $repository->countAttempts(self::IP, self::OWNER, 600));
        $this->assertSame(3, $repository->countAttemptsByIp(self::IP, 600));
        $this->assertSame(3, $repository->clearAttempts(self::IP, self::OWNER));
        $this->assertSame(0, $repository->countAttemptsByIp(self::IP, 600));
        $this->assertSame(1, $repository->countAttemptsByIp(self::IP . ' ', 600));
    }

    /**
     * End to end through the limiter: the attacker brute-forces `bob@`, then logs in successfully as `bób@` from the
     * same IP; bob's bucket stays blocked.
     */
    #[DataProvider('platforms')]
    public function testASuccessfulLookAlikeLoginDoesNotUnblockTheOwner(string $source, bool $exactCollation): void
    {
        $repository = new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation)));
        $limiter = new DatabaseRateLimiter($repository, 3, 600);
        $request = static fn (string $username): Request => Request::create('/login', 'POST', ['_username' => $username], server: ['REMOTE_ADDR' => self::IP]);

        for ($i = 0; $i < 3; ++$i) {
            $limiter->consume($request(self::OWNER));
        }
        $this->assertFalse($limiter->consume($request(self::OWNER))->isAccepted());

        $this->assertTrue($limiter->consume($request('bób@example.com'))->isAccepted());
        $limiter->reset($request('bób@example.com'));

        $this->assertFalse($limiter->consume($request(self::OWNER))->isAccepted(), 'the owner must stay blocked');
        $this->assertSame(3, $repository->countAttempts(self::IP, self::OWNER, 600));
    }

    /**
     * The listener gives both key columns the platform's NO PAD binary collation on MySQL / MariaDB.
     */
    #[DataProvider('platforms')]
    public function testTheListenerSetsTheExactCollation(string $source, bool $exactCollation): void
    {
        if (!$exactCollation) {
            $this->assertTrue(true);

            return;
        }
        $connection = $this->entityManager($source, true)->getConnection();
        $platform = $connection->getDatabasePlatform();
        $this->assertInstanceOf(AbstractMySQLPlatform::class, $platform);

        $collations = $connection->fetchAllKeyValue(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?) ORDER BY COLUMN_NAME',
            ['login_attempts', 'ip_address', 'username'],
        );
        $expected = ExactCollationListener::collationFor($platform);
        $this->assertContains($expected, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin']);
        $this->assertSame(['ip_address' => $expected, 'username' => $expected], $collations);
    }

    private function seededRepository(string $source, bool $exactCollation): LoginAttemptRepository
    {
        $repository = new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation)));
        for ($i = 0; $i < 3; ++$i) {
            $repository->recordAttempt(self::IP, self::OWNER);
        }
        foreach (self::LOOK_ALIKES as $lookAlike) {
            $repository->recordAttempt(self::IP, $lookAlike);
        }
        $repository->recordAttempt(self::IP . ' ', self::OWNER);

        return $repository;
    }

    private function registry(EntityManager $entityManager): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return $registry;
    }

    private function entityManager(string $source, bool $exactCollation): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }

        if ('sqlite' === $source) {
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
            // SQLite compares exactly unless told otherwise: NOCASE stands in for a folding MySQL collation
            $connection->executeStatement('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ip_address VARCHAR(45) NOT NULL COLLATE NOCASE, username VARCHAR(255) DEFAULT NULL COLLATE NOCASE, created_at DATETIME NOT NULL, blocked BOOLEAN DEFAULT 0 NOT NULL)');

            return new EntityManager($connection, $configuration);
        }

        $url = getenv($source);
        if (!\is_string($url) || '' === $url) {
            $this->markTestSkipped($source . ' is not set.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($url);
        $connection = DriverManager::getConnection($params, $configuration);
        $connection->executeStatement('DROP TABLE IF EXISTS login_attempts');
        $entityManager = new EntityManager($connection, $configuration, new EventManager());
        if ($exactCollation) {
            $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchemaTable], new ExactCollationListener($this->registry($entityManager)));
        }
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(LoginAttempt::class)]);

        return $entityManager;
    }
}
