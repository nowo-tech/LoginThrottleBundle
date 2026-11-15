<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\RateLimiter;

use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Tests\Support\LockingDelegateStore;
use PHPUnit\Framework\TestCase;
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

        $this->assertSame(['10.0.0.1', '10.0.0.1', '10.0.0.1', '10.0.0.2', '10.0.0.2', '10.0.0.1'], $store->units, 'one unit per decision and per reset with a username');
        $this->assertSame(0, $store->inner->countAttempts('10.0.0.1', 'alice', 600));
        $this->assertSame(1, $store->inner->countAttemptsByIp('10.0.0.1', 600));
        $this->assertSame(2, $store->inner->countAttemptsWithoutUsername('10.0.0.2', 600));
    }
}
