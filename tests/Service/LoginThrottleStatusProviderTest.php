<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Service;

use Nowo\LoginThrottleBundle\Service\LoginThrottleStatusProvider;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use PHPUnit\Framework\TestCase;

final class LoginThrottleStatusProviderTest extends TestCase
{
    public function testUnknownFirewallIsNotTracked(): void
    {
        $status = (new LoginThrottleStatusProvider([], new InMemoryLoginAttemptStore()))->status('main', '10.0.0.1', 'x');

        self::assertFalse($status->tracked);
        self::assertSame(0, $status->maxAttempts);
        self::assertSame('ip', $status->trackingType);
    }

    public function testCacheStorageOrMissingStoreIsNotTracked(): void
    {
        $cache = (new LoginThrottleStatusProvider(['main' => ['max_attempts' => 4, 'storage' => 'cache']], new InMemoryLoginAttemptStore()))->status('main', '10.0.0.1', 'X');
        self::assertFalse($cache->tracked);
        self::assertSame(4, $cache->maxAttempts);
        self::assertSame('username', $cache->trackingType);

        $noStore = (new LoginThrottleStatusProvider(['main' => ['max_attempts' => 4, 'storage' => 'database']]))->status('main', '10.0.0.1');
        self::assertFalse($noStore->tracked);
        self::assertSame('ip', $noStore->trackingType);
    }

    public function testDefaultsWhenConfigValuesAreMissing(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $store->recordAttempt('10.0.0.1', 'x');

        $status = (new LoginThrottleStatusProvider(['main' => ['storage' => 'database', 'max_attempts_per_ip' => 'n/a']], $store))->status('main', '10.0.0.1', 'X');

        self::assertTrue($status->tracked);
        self::assertSame(3, $status->maxAttempts);
        self::assertSame(1, $status->currentAttempts);
        self::assertSame(15, $status->maxAttemptsPerIp);
    }

    public function testFirewallConfigShapes(): void
    {
        $flat = ['max_attempts' => 5, 'firewall' => 'admin'];

        self::assertNull(LoginThrottleStatusProvider::firewallConfig(null, 'main'));
        self::assertSame($flat, LoginThrottleStatusProvider::firewallConfig($flat, 'admin'));
        self::assertNull(LoginThrottleStatusProvider::firewallConfig($flat, 'main'));
        self::assertNull(LoginThrottleStatusProvider::firewallConfig(['main' => 'invalid'], 'main'));
        self::assertSame(['max_attempts' => 2], LoginThrottleStatusProvider::firewallConfig(['main' => ['max_attempts' => 2]], 'main'));
    }
}
