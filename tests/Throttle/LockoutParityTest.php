<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Throttle;

use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProvider;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptViewStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * 3.9: what the status provider and the info service report is what the database limiter enforces.
 */
final class LockoutParityTest extends TestCase
{
    private const MAX = 3;
    private const PER_IP = 5;
    private const TIMEOUT = 600;

    /**
     * @return iterable<string, array{InMemoryLoginAttemptStore}>
     */
    public static function stores(): iterable
    {
        yield 'host store, original port only' => [new InMemoryLoginAttemptStore()];
        yield 'host store with entity-free reads' => [new InMemoryLoginAttemptViewStore()];
    }

    #[DataProvider('stores')]
    public function testSameUsernameLocksOutAfterMaxAttempts(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);

        for ($i = 1; $i <= 6; ++$i) {
            $this->assertParity($store, $limiter, $this->formRequest('10.0.0.1', 'Alice@Example.com '));
            $store->advance(10);
        }

        // Mixed case and spaces share the limiter's normalised bucket.
        $status = $this->provider($store)->status('main', '10.0.0.1', '  ALICE@example.COM');
        self::assertTrue($status->blocked);
        self::assertSame(3, $status->currentAttempts);
        self::assertSame(0, $status->remainingAttempts);
        self::assertSame('username', $status->trackingType);
        self::assertEquals($store->now->modify('-60 seconds')->modify('+600 seconds'), $status->retryAfter);

        // Another IP is a separate bucket.
        self::assertFalse($this->provider($store)->status('main', '10.0.0.2', 'alice@example.com')->blocked);
    }

    #[DataProvider('stores')]
    public function testRotatingUsernamesHitsThePerIpCap(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $username) {
            $this->assertParity($store, $limiter, $this->formRequest('10.0.0.9', $username));
            $store->advance(5);
        }

        $status = $this->provider($store)->status('main', '10.0.0.9', 'fresh-user');
        self::assertTrue($status->blocked);
        self::assertSame(0, $status->currentAttempts);
        self::assertSame(5, $status->currentIpAttempts);
        self::assertSame(self::PER_IP, $status->maxAttemptsPerIp);
    }

    #[DataProvider('stores')]
    public function testWindowExpiryAndResetUnblock(InMemoryLoginAttemptStore $store): void
    {
        $limiter = $this->limiter($store);

        for ($i = 0; $i < 3; ++$i) {
            $this->assertParity($store, $limiter, $this->formRequest('10.0.0.3', 'bob'));
        }
        self::assertTrue($this->provider($store)->status('main', '10.0.0.3', 'bob')->blocked);

        $store->advance(self::TIMEOUT + 1);
        $this->assertParity($store, $limiter, $this->formRequest('10.0.0.3', 'bob'));

        $limiter->reset($this->formRequest('10.0.0.3', 'bob'));
        $status = $this->provider($store)->status('main', '10.0.0.3', 'bob');
        self::assertFalse($status->blocked);
        self::assertSame(0, $status->currentAttempts);
        self::assertSame(3, $status->remainingAttempts);
    }

    public function testJsonLoginIsKeyedOnThePassportIdentifierWhenEnabled(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $limiter = $this->limiter($store, true);

        for ($i = 0; $i < 4; ++$i) {
            $this->assertParity($store, $limiter, $this->jsonRequest('10.0.0.4', 'Carol'), 'carol');
        }

        self::assertTrue($this->provider($store)->status('main', '10.0.0.4', 'carol')->blocked);
        // A different identifier from the same IP still has its own bucket (only the per-IP cap is shared).
        self::assertFalse($this->provider($store)->status('main', '10.0.0.4', 'dave')->blocked);

        $limiter->reset($this->jsonRequest('10.0.0.4', 'carol'));
        self::assertSame(0, $this->provider($store)->status('main', '10.0.0.4', 'carol')->currentAttempts);
    }

    public function testJsonLoginStaysIpOnlyByDefault(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $limiter = $this->limiter($store);

        for ($i = 0; $i < 3; ++$i) {
            $this->assertParity($store, $limiter, $this->jsonRequest('10.0.0.5', 'erin'), null);
        }

        self::assertTrue($this->provider($store)->status('main', '10.0.0.5')->blocked);
        self::assertSame(3, $store->countAttempts('10.0.0.5', null, self::TIMEOUT));
        self::assertSame(0, $store->countAttemptsByUsername('erin', self::TIMEOUT));
    }

    /**
     * KF-L-M1 (audit PoC P2): guess twice against a victim, log into an own account, repeat 20 times from one IP. With
     * IP-only keys the successful login must not wipe the IP's attempts (3.2.0–3.8.0 accepted 40 / 40 guesses).
     *
     * @return iterable<string, array{bool, int}>
     */
    public static function resetBypassModes(): iterable
    {
        yield 'json_login, IP-only keys (default)' => [false, 2];
        yield 'json_login, username_from_passport' => [true, 2];
    }

    #[DataProvider('resetBypassModes')]
    public function testOwnAccountLoginDoesNotReopenTheIpBudget(bool $usernameFromPassport, int $maxAccepted): void
    {
        $store = new InMemoryLoginAttemptStore();
        $limiter = $this->limiter($store, $usernameFromPassport);
        $accepted = 0;

        for ($cycle = 0; $cycle < 20; ++$cycle) {
            for ($guess = 0; $guess < 2; ++$guess) {
                $accepted += $limiter->consume($this->jsonRequest('6.6.6.6', 'victim'))->isAccepted() ? 1 : 0;
                $store->advance(1);
            }

            // Symfony: consume() in checkPassport, then reset() on the successful login of the attacker's own account.
            $own = $this->jsonRequest('6.6.6.6', 'attacker');
            $limiter->consume($own);
            $limiter->reset($own);
            $store->advance(1);
        }

        self::assertLessThanOrEqual($maxAccepted, $accepted);
        self::assertTrue($this->provider($store)->status('main', '6.6.6.6', $usernameFromPassport ? 'victim' : null)->blocked);
    }

    public function testSuccessfulLoginWithAUsernameStillClearsOnlyItsOwnBucket(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $limiter = $this->limiter($store);

        $limiter->consume($this->formRequest('10.0.0.8', 'alice'));
        $limiter->consume($this->formRequest('10.0.0.8', 'alice'));
        $limiter->consume($this->formRequest('10.0.0.8', 'bob'));
        $limiter->reset($this->formRequest('10.0.0.8', 'alice'));

        self::assertSame(0, $store->countAttempts('10.0.0.8', 'alice', self::TIMEOUT));
        self::assertSame(1, $store->countAttemptsByIp('10.0.0.8', self::TIMEOUT));
    }

    /**
     * Before the attempt: blocked ⇔ refused without recording. After it: the same verdict, remaining tokens and retry
     * time as the limiter returned. The info service reports the same figures.
     */
    private function assertParity(InMemoryLoginAttemptStore $store, DatabaseRateLimiter $limiter, Request $request, ?string $expectedKey = 'from-post'): void
    {
        $ip = (string) $request->getClientIp();
        $key = 'from-post' === $expectedKey ? mb_strtolower(trim((string) $request->request->get('_username'))) : $expectedKey;

        $before = $this->provider($store)->status('main', $ip, $key);
        $rowsBefore = $store->countAttemptsByIp($ip, self::TIMEOUT);
        $limit = $limiter->consume($request);
        $after = $this->provider($store)->status('main', $ip, $key);

        if ($before->blocked) {
            self::assertFalse($limit->isAccepted(), 'Status said blocked, the limiter accepted.');
            self::assertSame($rowsBefore, $store->countAttemptsByIp($ip, self::TIMEOUT), 'A refused attempt must not be recorded.');
        }

        self::assertSame(!$limit->isAccepted(), $after->blocked);
        self::assertSame($limit->getRemainingTokens(), $after->remainingAttempts);

        if (!$limit->isAccepted()) {
            self::assertEquals($limit->getRetryAfter(), $after->retryAfter);
        }

        $info = new LoginThrottleInfoService();
        $info->setRepository($store);
        $info->setFirewallsConfig($this->firewalls());
        self::assertEquals($after->toArray(), $info->getAttemptInfo('main', Request::create('/login', 'GET', server: ['REMOTE_ADDR' => $ip]), $key));
    }

    private function limiter(InMemoryLoginAttemptStore $store, bool $usernameFromPassport = false): DatabaseRateLimiter
    {
        $clock = new class($store) implements ClockInterface {
            public function __construct(private readonly InMemoryLoginAttemptStore $store)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->store->now;
            }
        };

        return new DatabaseRateLimiter($store, self::MAX, self::TIMEOUT, self::PER_IP, $clock, $usernameFromPassport);
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
        return ['main' => ['max_attempts' => self::MAX, 'timeout' => self::TIMEOUT, 'storage' => 'database', 'max_attempts_per_ip' => self::PER_IP]];
    }

    private function formRequest(string $ip, string $username): Request
    {
        return Request::create('/login', 'POST', ['_username' => $username, '_password' => 'x'], server: ['REMOTE_ADDR' => $ip]);
    }

    /**
     * What Symfony's login_throttling listener hands the limiter for json_login: no form fields, the passport
     * identifier on the request attributes.
     */
    private function jsonRequest(string $ip, string $identifier): Request
    {
        $request = Request::create('/api/login', 'POST', server: ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['username' => $identifier, 'password' => 'x']));
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $identifier);

        return $request;
    }
}
