<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\RateLimiter;

use Nowo\LoginThrottleBundle\Event\LoginThrottledEvent;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Tests\Support\LockingDelegateStore;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 3.15: with a store that implements LoginAttemptLockInterface, every decision and every reset is one unit under the
 * lock of the IP address, read and written through the store the unit receives.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class DatabaseRateLimiterLockTest extends TestCase
{
    public function testDecisionsAndResetsRunAsUnitsOfTheIpAddress(): void
    {
        $store = new LockingDelegateStore();
        $limiter = new DatabaseRateLimiter($store, 2, 600, 10, null, false, 2);
        $alice = Request::create('/login', 'POST', ['_username' => 'alice'], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $anonymous = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '10.0.0.2']);

        $this->assertTrue($limiter->consume($alice)->isAccepted());
        $this->assertFalse($limiter->consume($alice)->isAccepted(), 'the second attempt starts the lockout');
        $this->assertFalse($limiter->consume($alice)->isAccepted());
        $this->assertTrue($limiter->consume($anonymous)->isAccepted());
        $this->assertFalse($limiter->consume($anonymous)->isAccepted(), 'the bound without a username');
        $limiter->reset($alice);
        $limiter->reset($anonymous);

        $this->assertSame(['10.0.0.1', '10.0.0.1', '10.0.0.2', '10.0.0.2', '10.0.0.1'], $store->units, 'one unit per decision and per reset with a username; an attempt already refused takes no unit');
        $this->assertSame(0, $store->inner->countAttempts('10.0.0.1', 'alice', 600));
        $this->assertSame(1, $store->inner->countAttemptsByIp('10.0.0.1', 600));
        $this->assertSame(2, $store->inner->countAttemptsWithoutUsername('10.0.0.2', 600));
    }

    /**
     * Audit QU-M1: once a limit refuses an IP address, its flood is refused without taking the lock (no unit), with the
     * same answer and event as under the lock, and nothing is recorded.
     */
    public function testAnAttemptAlreadyRefusedTakesNoLock(): void
    {
        $store = new LockingDelegateStore();
        $events = new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                return $this->events[] = $event;
            }
        };
        $limiter = new DatabaseRateLimiter($store, 3, 600, 4, null, false, 2, $events);
        $request = static fn (?string $username, string $ip = '10.0.0.1'): Request => Request::create('/login', 'POST', null === $username ? [] : ['_username' => $username], [], [], ['REMOTE_ADDR' => $ip]);

        for ($i = 0; $i < 3; ++$i) {
            $limiter->consume($request('alice'));
        }
        $this->assertCount(3, $store->units);
        $limit = $limiter->consume($request('alice'));
        $this->assertFalse($limit->isAccepted());
        $this->assertSame(0, $limit->getRemainingTokens());
        $this->assertCount(3, $store->units, 'username bucket: no lock');

        $this->assertFalse($limiter->consume($request('bob'))->isAccepted(), 'the fourth attempt of the IP reaches the per-IP cap of 4');
        $this->assertFalse($limiter->consume($request('carol'))->isAccepted());
        $this->assertCount(4, $store->units, 'per-IP cap: no lock');

        $limiter->consume($request(null, '10.0.0.2'));
        $limiter->consume($request(null, '10.0.0.2'));
        $this->assertFalse($limiter->consume($request(null, '10.0.0.2'))->isAccepted());
        $this->assertCount(6, $store->units, 'bound without a username: no lock');
        $this->assertSame(4, $store->inner->countAttemptsByIp('10.0.0.1', 600), 'refused attempts are not recorded');

        $scopes = array_map(static fn (object $event): string => $event instanceof LoginThrottledEvent ? $event->scope . ($event->lockoutStarted ? '+' : '') : '', $events->events);
        $this->assertSame(['username+', 'username', 'ip+', 'ip', 'without_username+', 'without_username'], $scopes);
    }
}
