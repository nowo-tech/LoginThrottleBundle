<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Throttle;

use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiterFactory;
use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProvider;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptViewStore;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptWithoutUsernameStore;
use Nowo\LoginThrottleBundle\Throttle\ThrottleRules;
use Nowo\LoginThrottleBundle\Throttle\ThrottleStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * 3.13.0: `max_count_attempts_without_username` bounds the attempts of an IP that carry no usable username, counting
 * only that IP's username-less rows, while the per-IP cap keeps bounding the address as a whole.
 */
final class WithoutUsernameBoundTest extends TestCase
{
    private const IP = '10.0.0.1';
    private const MAX = 3;
    private const PER_IP = 30;
    private const WITHOUT_USERNAME = 4;
    private const TIMEOUT = 600;

    /**
     * @return iterable<string, array{InMemoryLoginAttemptStore}>
     */
    public static function stores(): iterable
    {
        yield 'host store, original port only' => [new InMemoryLoginAttemptStore()];
        yield 'host store with entity-free reads' => [new InMemoryLoginAttemptViewStore()];
        yield 'host store with the username-less reads' => [new InMemoryLoginAttemptWithoutUsernameStore()];
    }

    public function testDefaultIsThePerIpCapSoNothingChanges(): void
    {
        $store = new InMemoryLoginAttemptStore();

        $rules = new ThrottleRules($store, self::MAX, self::TIMEOUT);
        self::assertSame(15, $rules->maxAttemptsWithoutUsername());
        self::assertSame(15, $rules->limitFor(null));
        self::assertFalse($rules->boundsAttemptsWithoutUsername());

        // equal to the cap: the same bound as before, no extra reads
        $equal = new ThrottleRules($store, self::MAX, self::TIMEOUT, 7, 7);
        self::assertSame(7, $equal->maxAttemptsWithoutUsername());
        self::assertFalse($equal->boundsAttemptsWithoutUsername());

        // the per-IP cap applies on top: a higher value is clamped when the rules are built directly
        self::assertSame(7, (new ThrottleRules($store, self::MAX, self::TIMEOUT, 7, 50))->maxAttemptsWithoutUsername());
        self::assertSame(1, (new ThrottleRules($store, self::MAX, self::TIMEOUT, 7, 0))->maxAttemptsWithoutUsername());

        $bounded = new ThrottleRules($store, self::MAX, self::TIMEOUT, 7, 2);
        self::assertTrue($bounded->boundsAttemptsWithoutUsername());
        self::assertSame(2, $bounded->limitFor(null));
        self::assertSame(self::MAX, $bounded->limitFor('alice'));
    }

    /**
     * The point of the option: a shared IP keeps a generous cap for named logins while username-less requests get a
     * tight one.
     */
    #[DataProvider('stores')]
    public function testUsernameLessRequestsHitTheirOwnBoundWellBelowThePerIpCap(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);
        $accepted = 0;

        for ($i = 0; $i < 10; ++$i) {
            $accepted += $this->assertParity($store, $limiter, $this->anonymous()) ? 1 : 0;
            $store->advance(5);
        }

        // like max_count_attempts, the attempt that reaches the bound is refused
        self::assertSame(self::WITHOUT_USERNAME - 1, $accepted);
        self::assertSame(self::WITHOUT_USERNAME, $store->countAttemptsByIp(self::IP, self::TIMEOUT), 'refused attempts are not recorded');

        $status = $this->provider($store)->status('main', self::IP);
        self::assertTrue($status->blocked);
        self::assertSame(self::WITHOUT_USERNAME, $status->maxAttempts);
        self::assertSame(self::WITHOUT_USERNAME, $status->currentAttempts);
        self::assertSame(ThrottleStatus::TRACKING_IP, $status->trackingType);
        self::assertSame(self::PER_IP, $status->maxAttemptsPerIp);
        // the oldest username-less attempt leaves the window at 10:00:00 + timeout
        self::assertEquals(new \DateTimeImmutable('2026-11-10T10:10:00+00:00'), $status->retryAfter);

        // named logins from the same IP are untouched: the per-IP cap (30) is far away
        self::assertTrue($limiter->consume($this->named('alice'))->isAccepted());
        self::assertFalse($this->provider($store)->status('main', self::IP, 'alice')->blocked);

        // the bound decays with timeout
        $store->advance(self::TIMEOUT);
        self::assertTrue($limiter->consume($this->anonymous())->isAccepted());
    }

    /**
     * Alternating named and unnamed requests: named rows never count for the username-less bound, nor do they let
     * username-less requests through once the bound is reached.
     */
    #[DataProvider('stores')]
    public function testAlternatingNamedAndUnnamedRequestsDoesNotBypassTheBound(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);
        $anonymousAccepted = 0;
        $namedAccepted = 0;

        for ($i = 0; $i < 12; ++$i) {
            $anonymousAccepted += $this->assertParity($store, $limiter, $this->anonymous()) ? 1 : 0;
            $namedAccepted += $this->assertParity($store, $limiter, $this->named('user' . $i)) ? 1 : 0;
            $store->advance(1);
        }

        self::assertSame(self::WITHOUT_USERNAME - 1, $anonymousAccepted);
        self::assertSame(12, $namedAccepted, 'one failure per account, far below max_count_attempts and the cap');
    }

    /**
     * Empty, blank and unusable usernames are username-less: they share one bound, whichever field carries them.
     */
    #[DataProvider('stores')]
    public function testBlankAndUnusableUsernamesShareTheUsernameLessBound(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);
        $requests = [
            Request::create('/login', 'POST', ['_username' => '', '_password' => 'x'], server: ['REMOTE_ADDR' => self::IP]),
            Request::create('/login', 'POST', ['_username' => '   ', '_password' => 'x'], server: ['REMOTE_ADDR' => self::IP]),
            Request::create('/login', 'POST', ['email' => "\t", '_password' => 'x'], server: ['REMOTE_ADDR' => self::IP]),
            Request::create('/login', 'POST', ['_username' => "attacker\t", '_password' => 'x'], server: ['REMOTE_ADDR' => self::IP]),
            Request::create('/login', 'POST', ['login_form' => ['_username' => "\u{200B}"]], server: ['REMOTE_ADDR' => self::IP]),
        ];
        // as Symfony's listener hands them over: the blank identifier in LAST_USERNAME
        $listener = Request::create('/login', 'POST', ['_username' => ' '], server: ['REMOTE_ADDR' => self::IP]);
        $listener->attributes->set(SecurityRequestAttributes::LAST_USERNAME, ' ');
        $requests[] = $listener;

        $accepted = 0;
        foreach ($requests as $request) {
            $accepted += $limiter->consume($request)->isAccepted() ? 1 : 0;
        }

        self::assertSame(self::WITHOUT_USERNAME - 1, $accepted);
        self::assertSame(0, $store->countAttemptsByUsername('attacker', self::TIMEOUT));
    }

    /**
     * KF-L-M1 kept: reset() without a username clears nothing, and a successful named login never reopens the
     * username-less budget (its cleared failures only lose their username, so they count in it).
     */
    #[DataProvider('stores')]
    public function testResetNeverReopensTheUsernameLessBudget(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);
        for ($i = 0; $i < self::WITHOUT_USERNAME; ++$i) {
            $limiter->consume($this->anonymous());
        }
        self::assertFalse($limiter->consume($this->anonymous())->isAccepted());

        $limiter->reset($this->anonymous());
        $limiter->reset(Request::create('/login', 'POST', ['_username' => ' '], server: ['REMOTE_ADDR' => self::IP]));
        self::assertFalse($limiter->consume($this->anonymous())->isAccepted(), 'a username-less reset clears nothing');

        $own = $this->named('attacker');
        self::assertTrue($limiter->consume($own)->isAccepted());
        $limiter->reset($own);
        self::assertFalse($limiter->consume($this->anonymous())->isAccepted(), 'a successful named login clears nothing of it');
        self::assertSame(self::WITHOUT_USERNAME, $store->countAttemptsByIp(self::IP, self::TIMEOUT));
    }

    /**
     * Failures a successful login cleared keep their row without the username (3.11.0): they count for the
     * username-less bound too. That only ever tightens it.
     */
    #[DataProvider('stores')]
    public function testClearedFailuresCountForTheUsernameLessBound(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);
        for ($i = 0; $i < 2; ++$i) {
            $limiter->consume($this->named('user' . $i));
            $success = $this->named('user' . $i);
            $limiter->consume($success);
            $limiter->reset($success);
        }

        self::assertSame(2, $this->provider($store)->status('main', self::IP)->currentAttempts);
        self::assertTrue($limiter->consume($this->anonymous())->isAccepted());
        self::assertFalse($limiter->consume($this->anonymous())->isAccepted());
    }

    /**
     * With username_from_passport, json_login is keyed on the passport identifier: the username-less bound does not
     * apply to it, even when that bound is exhausted.
     */
    #[DataProvider('stores')]
    public function testPassportIdentifierPathIsUnaffected(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store, true);
        for ($i = 0; $i < self::WITHOUT_USERNAME; ++$i) {
            $limiter->consume($this->anonymous());
        }
        self::assertFalse($limiter->consume($this->anonymous())->isAccepted());

        $accepted = 0;
        foreach (['carol', 'dave', 'erin', 'frank', 'grace', 'heidi'] as $identifier) {
            $accepted += $limiter->consume($this->json($identifier))->isAccepted() ? 1 : 0;
        }
        self::assertSame(6, $accepted);
        self::assertSame(1, $store->countAttemptsByUsername('carol', self::TIMEOUT));

        // without the option the same json_login requests are username-less, and the bound refuses them
        self::assertFalse($this->limiter($store)->consume($this->json('ivan'))->isAccepted());
    }

    /**
     * The per-IP cap still applies on top: a username-less request is refused once the IP's total reaches it, even
     * with the username-less bound far away.
     */
    #[DataProvider('stores')]
    public function testThePerIpCapStillAppliesOnTop(InMemoryLoginAttemptStore $store): void
    {
        $limiter = new DatabaseRateLimiter($store, self::MAX, self::TIMEOUT, 6, $this->clock($store), false, 5);
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume($this->named('user' . $i));
            $store->advance(1);
        }

        $refused = $limiter->consume($this->anonymous());
        self::assertFalse($refused->isAccepted(), 'the sixth attempt of the IP reaches the cap');
        self::assertSame(5, $refused->getLimit());
        self::assertSame(0, $refused->getRemainingTokens());
        // the cap's retry time: the IP's oldest attempt (a named one)
        self::assertEquals(new \DateTimeImmutable('2026-11-10T10:10:00+00:00'), $refused->getRetryAfter());

        $blocked = $limiter->consume($this->anonymous());
        self::assertFalse($blocked->isAccepted());
        self::assertEquals(new \DateTimeImmutable('2026-11-10T10:10:00+00:00'), $blocked->getRetryAfter());

        $status = (new ThrottleRules($store, self::MAX, self::TIMEOUT, 6, 5))->status(self::IP, null);
        self::assertTrue($status->blocked);
        self::assertSame(1, $status->currentAttempts);
        self::assertSame(6, $status->currentIpAttempts);
    }

    public function testFactoryPassesTheBound(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $limiter = (new DatabaseRateLimiterFactory($store, self::MAX, self::TIMEOUT, null, false, 2))->create();

        self::assertTrue($limiter->consume($this->anonymous())->isAccepted());
        $refused = $limiter->consume($this->anonymous());
        self::assertFalse($refused->isAccepted());
        self::assertSame(2, $refused->getLimit());
    }

    public function testStatusProviderReadsTheBoundFromTheFirewallConfig(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $store->recordAttempt(self::IP, null);

        $bounded = (new LoginThrottleStatusProvider(['main' => ['storage' => 'database', 'max_attempts_without_username' => 2]], $store))->status('main', self::IP);
        self::assertSame(2, $bounded->maxAttempts);
        self::assertSame(1, $bounded->remainingAttempts);

        $default = (new LoginThrottleStatusProvider(['main' => ['storage' => 'database', 'max_attempts_without_username' => 'n/a']], $store))->status('main', self::IP);
        self::assertSame(15, $default->maxAttempts);
    }

    /**
     * Status of the username-less bucket: blocked exactly when the limiter's next username-less consume() refuses,
     * and the info service reports the same. Returns whether the limiter accepted.
     */
    private function assertParity(InMemoryLoginAttemptStore $store, DatabaseRateLimiter $limiter, Request $request): bool
    {
        $key = \is_string($request->request->get('_username')) ? $request->request->get('_username') : null;
        $before = $this->provider($store)->status('main', self::IP, $key);
        $rowsBefore = $store->countAttemptsByIp(self::IP, self::TIMEOUT);
        $limit = $limiter->consume($request);
        $after = $this->provider($store)->status('main', self::IP, $key);

        if ($before->blocked) {
            self::assertFalse($limit->isAccepted(), 'Status said blocked, the limiter accepted.');
            self::assertSame($rowsBefore, $store->countAttemptsByIp(self::IP, self::TIMEOUT), 'A refused attempt must not be recorded.');
        }
        self::assertSame(!$limit->isAccepted(), $after->blocked);
        self::assertSame($limit->getRemainingTokens(), $after->remainingAttempts);
        self::assertSame($limit->getLimit(), $after->maxAttempts);
        if (!$limit->isAccepted()) {
            self::assertEquals($limit->getRetryAfter(), $after->retryAfter);
        }

        $info = new LoginThrottleInfoService();
        $info->setRepository($store);
        $info->setFirewallsConfig($this->firewalls());
        self::assertEquals($after->toArray(), $info->getAttemptInfo('main', Request::create('/login', 'GET', server: ['REMOTE_ADDR' => self::IP]), $key));

        return $limit->isAccepted();
    }

    private function limiter(InMemoryLoginAttemptStore $store, bool $usernameFromPassport = false): DatabaseRateLimiter
    {
        return new DatabaseRateLimiter($store, self::MAX, self::TIMEOUT, self::PER_IP, $this->clock($store), $usernameFromPassport, self::WITHOUT_USERNAME);
    }

    private function clock(InMemoryLoginAttemptStore $store): ClockInterface
    {
        return new class($store) implements ClockInterface {
            public function __construct(private readonly InMemoryLoginAttemptStore $store)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->store->now;
            }
        };
    }

    private function provider(InMemoryLoginAttemptStore $store): LoginThrottleStatusProvider
    {
        return new LoginThrottleStatusProvider($this->firewalls(), $store);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function firewalls(): array
    {
        return ['main' => [
            'max_attempts' => self::MAX,
            'timeout' => self::TIMEOUT,
            'storage' => 'database',
            'max_attempts_per_ip' => self::PER_IP,
            'max_attempts_without_username' => self::WITHOUT_USERNAME,
        ]];
    }

    private function anonymous(): Request
    {
        return Request::create('/login', 'POST', ['_password' => 'x'], server: ['REMOTE_ADDR' => self::IP]);
    }

    private function named(string $username): Request
    {
        $request = Request::create('/login', 'POST', ['_username' => $username, '_password' => 'x'], server: ['REMOTE_ADDR' => self::IP]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return $request;
    }

    /**
     * What Symfony's login_throttling listener hands the limiter for json_login: no form fields, the passport
     * identifier on the request attributes.
     */
    private function json(string $identifier): Request
    {
        $request = Request::create('/api/login', 'POST', server: ['REMOTE_ADDR' => self::IP, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['username' => $identifier, 'password' => 'x']));
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $identifier);

        return $request;
    }
}
