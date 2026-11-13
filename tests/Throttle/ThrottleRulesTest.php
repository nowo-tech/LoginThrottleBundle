<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Throttle;

use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptViewStore;
use Nowo\LoginThrottleBundle\Throttle\ThrottleRules;
use Nowo\LoginThrottleBundle\Throttle\ThrottleStatus;
use PHPUnit\Framework\TestCase;

final class ThrottleRulesTest extends TestCase
{
    public function testDefaultPerIpCapIsFiveTimesTheLimitAndAtLeastOne(): void
    {
        self::assertSame(15, (new ThrottleRules(new InMemoryLoginAttemptStore(), 3, 600))->maxAttemptsPerIp());
        self::assertSame(7, (new ThrottleRules(new InMemoryLoginAttemptStore(), 3, 600, 7))->maxAttemptsPerIp());
    }

    public function testRetryAfterIsNullWithoutAttempts(): void
    {
        self::assertNull((new ThrottleRules(new InMemoryLoginAttemptStore(), 3, 600))->retryAfter('10.0.0.1', 'x'));
        self::assertNull((new ThrottleRules(new InMemoryLoginAttemptViewStore(), 3, 600))->retryAfter('10.0.0.1', 'x'));
    }

    public function testEntityFreeStoreIsReadWithoutGetAttempts(): void
    {
        $store = new InMemoryLoginAttemptViewStore();
        $store->recordAttempt('10.0.0.1', 'x');
        $oldest = $store->now;
        $store->advance(30);
        $store->recordAttempt('10.0.0.1', 'x');

        $retryAfter = (new ThrottleRules($store, 3, 600))->retryAfter('10.0.0.1', 'x');

        self::assertEquals($oldest->modify('+600 seconds'), $retryAfter);
        self::assertSame(0, $store->getAttemptsCalls);
        self::assertCount(2, $store->getAttemptViews('10.0.0.1', 'x', 600));
    }

    public function testStatusOfAnEmptyBucket(): void
    {
        $status = (new ThrottleRules(new InMemoryLoginAttemptStore(), 3, 600))->status('10.0.0.1', null);

        // 3.10.2: the IP bucket's limit is the per-IP cap (3 × 5), not max_count_attempts
        self::assertEquals(new ThrottleStatus(0, 15, 15, false, null, ThrottleStatus::TRACKING_IP, 0, 15), $status);
        self::assertTrue($status->tracked);
    }
}
