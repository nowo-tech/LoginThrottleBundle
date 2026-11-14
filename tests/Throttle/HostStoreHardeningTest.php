<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Throttle;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptViewRepositoryInterface;
use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProvider;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use Nowo\LoginThrottleBundle\Throttle\ThrottleRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 3.13.1: host stores that differ from the bundle's own in ways the port allows.
 *
 * - I-1: {@see ThrottleRules::status()} only applies the username-less bound when the option is set, so a store whose
 *   `countAttempts($ip, null)` counts more than `countAttemptsByIp($ip)` cannot make the status say "blocked" where
 *   the limiter accepts.
 * - I-5: a store that writes `''` instead of null for "no username" still has those rows counted by the bound.
 */
final class HostStoreHardeningTest extends TestCase
{
    private const IP = '10.0.0.1';
    private const MAX = 3;
    private const PER_IP = 30;
    private const WITHOUT_USERNAME = 4;
    private const TIMEOUT = 600;

    public function testStatusIgnoresTheUsernameLessCountWhenTheOptionIsUnset(): void
    {
        $store = new class extends InMemoryLoginAttemptStore {
            // A host store that counts more for the IP-only bucket than for the per-IP cap.
            public function countAttempts(string $ipAddress, ?string $username, int $seconds): int
            {
                return parent::countAttempts($ipAddress, $username, $seconds) + (null === $username ? 100 : 0);
            }
        };
        $rules = new ThrottleRules($store, self::MAX, self::TIMEOUT, self::PER_IP);
        self::assertFalse($rules->boundsAttemptsWithoutUsername());

        $status = $rules->status(self::IP, null);
        self::assertFalse($status->blocked, 'The status must not report a block the limiter does not enforce.');
        self::assertNull($status->retryAfter);

        $providerStatus = (new LoginThrottleStatusProvider($this->firewalls(null), $store))->status('main', self::IP);
        self::assertFalse($providerStatus->blocked);

        $limiter = new DatabaseRateLimiter($store, self::MAX, self::TIMEOUT, self::PER_IP, $this->clock($store));
        self::assertTrue($limiter->consume($this->anonymous())->isAccepted());
    }

    /**
     * @return iterable<string, array{InMemoryLoginAttemptStore}>
     */
    public static function emptyStringStores(): iterable
    {
        yield 'host store, original port only' => [new class extends InMemoryLoginAttemptStore {
            public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
            {
                parent::recordAttempt($ipAddress, $username ?? '');

                return new LoginAttempt($ipAddress, $username);
            }
        }];
        yield 'host store with entity-free reads' => [new class extends InMemoryLoginAttemptStore implements LoginAttemptViewRepositoryInterface {
            public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
            {
                parent::recordAttempt($ipAddress, $username ?? '');

                return new LoginAttempt($ipAddress, $username);
            }

            public function getAttemptViews(string $ipAddress, ?string $username, int $seconds): array
            {
                return array_map(
                    static fn (array $row): LoginAttemptView => new LoginAttemptView(null, $row['ip'], $row['username'], $row['at']),
                    $this->matching($ipAddress, $username, $seconds),
                );
            }

            public function findOldestAttemptAt(string $ipAddress, ?string $username, int $seconds): ?\DateTimeImmutable
            {
                $rows = $this->matching($ipAddress, $username, $seconds);

                return [] === $rows ? null : $rows[array_key_last($rows)]['at'];
            }
        }];
    }

    #[DataProvider('emptyStringStores')]
    public function testEmptyStringUsernamesCountForTheUsernameLessBound(InMemoryLoginAttemptStore $store): void
    {
        $limiter = new DatabaseRateLimiter($store, self::MAX, self::TIMEOUT, self::PER_IP, $this->clock($store), false, self::WITHOUT_USERNAME);
        $provider = new LoginThrottleStatusProvider($this->firewalls(self::WITHOUT_USERNAME), $store);
        $accepted = 0;

        for ($i = 0; $i < 10; ++$i) {
            $before = $provider->status('main', self::IP);
            $limit = $limiter->consume($this->anonymous());
            if ($before->blocked) {
                self::assertFalse($limit->isAccepted(), 'Status said blocked, the limiter accepted.');
            }
            $accepted += $limit->isAccepted() ? 1 : 0;
            $store->advance(5);
        }

        // the attempt that reaches the bound is recorded and refused, like the bundle's own stores
        self::assertSame(self::WITHOUT_USERNAME - 1, $accepted);
        $status = $provider->status('main', self::IP);
        self::assertTrue($status->blocked);
        self::assertSame(self::WITHOUT_USERNAME, $status->currentAttempts);
        self::assertSame(self::WITHOUT_USERNAME, $store->countAttemptsByIp(self::IP, self::TIMEOUT), 'refused attempts are not recorded');
        // the oldest username-less attempt leaves the window at 10:00:00 + timeout
        self::assertEquals(new \DateTimeImmutable('2026-11-10T10:10:00+00:00'), $status->retryAfter);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function firewalls(?int $withoutUsername): array
    {
        return ['main' => [
            'max_attempts' => self::MAX,
            'timeout' => self::TIMEOUT,
            'storage' => 'database',
            'max_attempts_per_ip' => self::PER_IP,
            'max_attempts_without_username' => $withoutUsername,
        ]];
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

    private function anonymous(): Request
    {
        return Request::create('/login', 'POST', ['_password' => 'x'], server: ['REMOTE_ADDR' => self::IP]);
    }
}
