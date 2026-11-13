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
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * REQ-SEC-006 (3.11.0): look-alike usernames (`Bob`, `bób`, `bob `, `bob\n`, NBSP, ZWSP) never clear the attempts of
 * `bob`; spelling variants (case, accents, width) count in bob's bucket, so they never add guesses either.
 *
 * Runs on SQLite with `COLLATE NOCASE` columns always, and on MySQL / MariaDB when LOGIN_THROTTLE_TEST_MYSQL_URL /
 * LOGIN_THROTTLE_TEST_MARIADB_URL point at a throwaway database (the tables are dropped first). The "legacy" variants
 * keep the server's default folding collation (a table created before 3.11.0, without the ALTER of UPGRADING): the
 * repository's PHP re-check alone must keep the buckets right.
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

    /**
     * Counting (3.11.0): the per-username bucket is the folded skeleton over the IP's attempts, so case and accent
     * variants (`Bob`, `bób`) count for `bob`; whitespace, control and format variants stay separate buckets.
     */
    #[DataProvider('platforms')]
    public function testCountingUsesTheFoldedSkeletonOverTheIpsAttempts(string $source, bool $exactCollation): void
    {
        $repository = $this->seededRepository($source, $exactCollation);
        $expected = [
            self::OWNER => 5,
            'Bob@example.com' => 5,
            'bób@example.com' => 5,
            'bob@example.com ' => 2,
            "bob@example.com\n" => 1,
            "bob@example.com\u{00A0}" => 2, // NFKD turns NBSP into a space
            "bob@example.com\u{200B}" => 1,
        ];

        foreach ($expected as $username => $count) {
            $label = json_encode($username, \JSON_THROW_ON_ERROR);
            $this->assertSame($count, $repository->countAttempts(self::IP, $username, 600), $label);
            $this->assertCount($count, $repository->getAttemptViews(self::IP, $username, 600), $label);
            // across IPs the count stays exact
            $this->assertSame(self::OWNER === $username ? 4 : 1, $repository->countAttemptsByUsername($username, 600), $label);
        }
        $this->assertTrue($repository->isBlocked(self::IP, self::OWNER, 5, 600));
        $this->assertFalse($repository->isBlocked(self::IP, self::OWNER, 6, 600));
        $this->assertCount(4, $repository->getAttempts('', self::OWNER, 600));
        $this->assertSame(3 + \count(self::LOOK_ALIKES), $repository->countAttemptsByIp(self::IP, 600));
        $this->assertSame(3 + \count(self::LOOK_ALIKES), $repository->countAttempts(self::IP, null, 600));
        $this->assertSame(1, $repository->countAttemptsByIp(self::IP . ' ', 600));
    }

    /**
     * F2: a successful login on a look-alike account clears only that account's own attempts, never the owner's.
     * Clearing deletes the newest row of the bucket (the successful request's own attempt) and takes the username off
     * the older ones, which keep counting for the IP (3.11.0).
     */
    #[DataProvider('platforms')]
    public function testClearingALookAlikeKeepsTheOwnersAttempts(string $source, bool $exactCollation): void
    {
        $repository = $this->seededRepository($source, $exactCollation);

        foreach (self::LOOK_ALIKES as $lookAlike) {
            $this->assertSame(1, $repository->clearAttempts(self::IP, $lookAlike), json_encode($lookAlike, \JSON_THROW_ON_ERROR));
        }
        $this->assertSame(0, $repository->clearAttempts(self::IP, 'bóB@example.com'));

        // each look-alike bucket held one row: its newest row is that row, so it is gone
        $this->assertSame(3, $repository->countAttemptsByIp(self::IP, 600));

        $this->assertSame(3, $repository->countAttempts(self::IP, self::OWNER, 600));
        $this->assertSame(3, $repository->clearAttempts(self::IP, self::OWNER));
        $this->assertSame(0, $repository->countAttempts(self::IP, self::OWNER, 600));
        $this->assertSame(2, $repository->countAttemptsByIp(self::IP, 600), 'the two older attempts still count for the IP');
        $this->assertSame(1, $repository->countAttemptsByIp(self::IP . ' ', 600));
        $this->assertSame(1, $repository->countAttempts(self::IP . ' ', self::OWNER, 600));
    }

    /**
     * End to end through the limiter: the attacker brute-forces `bob@`, then tries a successful login as `bób@` from
     * the same IP; the variant shares bob's blocked bucket, and its reset clears nothing of bob's.
     */
    #[DataProvider('platforms')]
    public function testASuccessfulLookAlikeLoginDoesNotUnblockTheOwner(string $source, bool $exactCollation): void
    {
        $repository = new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation)));
        $limiter = new DatabaseRateLimiter($repository, 3, 600);

        for ($i = 0; $i < 3; ++$i) {
            $limiter->consume(self::login(self::OWNER));
        }
        $this->assertFalse($limiter->consume(self::login(self::OWNER))->isAccepted());

        $this->assertFalse($limiter->consume(self::login('bób@example.com'))->isAccepted(), 'an accent variant shares the bucket');
        $limiter->reset(self::login('bób@example.com'));

        $this->assertFalse($limiter->consume(self::login(self::OWNER))->isAccepted(), 'the owner must stay blocked');
        $this->assertSame(3, $repository->countAttempts(self::IP, self::OWNER, 600));
    }

    /**
     * Audit F-LT-2: case, accent, NFD and fullwidth variants of one username never multiply its budget.
     */
    #[DataProvider('platforms')]
    public function testSpellingVariantsShareOneBudget(string $source, bool $exactCollation): void
    {
        $limiter = new DatabaseRateLimiter(new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation))), 3, 600);
        $accepted = 0;
        foreach (['bob@example.com', 'BOB@example.com', 'bób@example.com', 'bòb@example.com', 'böb@example.com', "bo\u{0301}b@example.com", 'ｂｏｂ@example.com', 'bõb@example.com'] as $variant) {
            for ($i = 0; $i < 3; ++$i) {
                $accepted += $limiter->consume(self::login($variant))->isAccepted() ? 1 : 0;
            }
        }

        $this->assertSame(2, $accepted);
    }

    /**
     * Audit F-LT-6: a successful login must not spend the per-IP budget. Symfony's limiter consumes a token on every
     * attempt, successful ones included, so the attempt a successful request records is deleted when it resets.
     */
    #[DataProvider('platforms')]
    public function testSuccessfulLoginsNeverExhaustThePerIpCap(string $source, bool $exactCollation): void
    {
        $repository = new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation)));
        $limiter = new DatabaseRateLimiter($repository, 3, 600); // per-IP cap 15
        $accepted = 0;

        // one office behind a single NAT address: 20 different people log in successfully within the window
        for ($i = 0; $i < 20; ++$i) {
            $login = self::login('user' . $i . '@example.com');
            $accepted += $limiter->consume($login)->isAccepted() ? 1 : 0;
            $limiter->reset($login);
        }

        $this->assertSame(20, $accepted);
        $this->assertSame(0, $repository->countAttemptsByIp(self::IP, 600));
    }

    /**
     * The same with a failure before each success: the failures keep counting for the IP (the cap bounds any reset
     * confusion), but a request whose username is unusable is bounded by that cap, not by `max_count_attempts`.
     */
    #[DataProvider('platforms')]
    public function testFailuresBeforeASuccessOnlyCountForTheIpCap(string $source, bool $exactCollation): void
    {
        $repository = new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation)));
        $limiter = new DatabaseRateLimiter($repository, 3, 600);
        $unusable = static fn (): Request => self::login("attacker\t");

        for ($i = 0; $i < 4; ++$i) {
            $user = 'user' . $i . '@example.com';
            $this->assertTrue($limiter->consume(self::login($user))->isAccepted(), 'the failed attempt');
            $login = self::login($user);
            $this->assertTrue($limiter->consume($login)->isAccepted(), 'the successful attempt');
            $limiter->reset($login);
            $this->assertSame(0, $repository->countAttempts(self::IP, $user, 600));
        }

        // 4 failures left, each without a username now: more than max_count_attempts (3), well under the cap (15)
        $this->assertSame(4, $repository->countAttemptsByIp(self::IP, 600));
        $this->assertTrue($limiter->consume($unusable())->isAccepted(), 'an unusable username is bounded by the cap');

        // KF-L-M1: a successful login without a usable username clears nothing
        $before = $repository->countAttemptsByIp(self::IP, 600);
        $limiter->reset($unusable());
        $this->assertSame($before, $repository->countAttemptsByIp(self::IP, 600));
    }

    /**
     * Audit F-LT-1: Symfony authenticates `_username` and stores it as LAST_USERNAME; an extra
     * `login_form[_username]=victim` field on the attacker's own successful login no longer resets the victim.
     */
    #[DataProvider('platforms')]
    public function testFieldConfusionNoLongerResetsTheVictim(string $source, bool $exactCollation): void
    {
        $limiter = new DatabaseRateLimiter(new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation))), 3, 600);
        $ownLogin = static fn (): Request => self::login('attacker@example.com', ['login_form' => ['_username' => 'victim@example.com']]);
        $accepted = 0;
        for ($round = 0; $round < 20; ++$round) {
            $accepted += $limiter->consume(self::login('victim@example.com'))->isAccepted() ? 1 : 0;
            $limiter->consume($ownLogin());
            $limiter->reset($ownLogin());
        }

        $this->assertSame(2, $accepted);
    }

    /**
     * Without Symfony's attribute (the limiter called directly) the POST fields decide, and the confusion still works
     * on the username bucket; cleared attempts keep counting for the IP, so the per-IP cap (15) still bounds it.
     */
    #[DataProvider('platforms')]
    public function testThePerIpCapBoundsAnyResetConfusion(string $source, bool $exactCollation): void
    {
        $limiter = new DatabaseRateLimiter(new LoginAttemptRepository($this->registry($this->entityManager($source, $exactCollation))), 3, 600);
        $guess = static fn (): Request => Request::create('/login', 'POST', ['_username' => 'victim@example.com'], server: ['REMOTE_ADDR' => self::IP]);
        $ownLogin = static fn (): Request => Request::create('/login', 'POST', ['login_form' => ['_username' => 'victim@example.com'], '_username' => 'attacker@example.com'], server: ['REMOTE_ADDR' => self::IP]);
        $accepted = 0;
        for ($round = 0; $round < 20; ++$round) {
            $accepted += $limiter->consume($guess())->isAccepted() ? 1 : 0;
            $limiter->consume($ownLogin());
            $limiter->reset($ownLogin());
        }

        $this->assertLessThan(15, $accepted, 'before 3.11.0: 20 of 20');
    }

    /**
     * A login request as Symfony's login_throttling listener hands it over: POST fields plus the authenticated
     * identifier in LAST_USERNAME.
     *
     * @param array<string, mixed> $extra
     */
    private static function login(string $username, array $extra = []): Request
    {
        $request = Request::create('/login', 'POST', ['_username' => $username] + $extra, server: ['REMOTE_ADDR' => self::IP]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return $request;
    }

    /**
     * The listener gives both key columns the platform's NO PAD binary collation on MySQL / MariaDB.
     */
    #[DataProvider('platforms')]
    public function testTheListenerSetsTheExactCollation(string $source, bool $exactCollation): void
    {
        if (!$exactCollation) {
            $this->addToAssertionCount(1); // the legacy variants keep the server's default collation

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
