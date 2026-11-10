<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Service;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests for LoginThrottleInfoService.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoginThrottleInfoServiceTest extends TestCase
{
    private LoginThrottleInfoService $service;
    private MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LoginAttemptRepositoryInterface::class);
        $this->service = new LoginThrottleInfoService();
        $this->service->setRepository($this->repository);
    }

    public function testGetAttemptInfoReturnsDefaultWhenNoConfig(): void
    {
        $this->service->setFirewallsConfig(null);
        $request = Request::create('/login', 'POST');

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(0, $result['current_attempts']);
        $this->assertSame(0, $result['max_attempts']);
        $this->assertSame(0, $result['remaining_attempts']);
        $this->assertFalse($result['is_blocked']);
        $this->assertNull($result['retry_after']);
        $this->assertSame('ip', $result['tracking_type']);
    }

    /**
     * 3.9: the database figures are the limiter's IP + username bucket (normalised), not a cross-IP username count.
     */
    public function testDatabaseFiguresFollowTheLimiterBucket(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $store->recordAttempt('192.168.1.1', 'test@example.com');
        $store->recordAttempt('192.168.1.1', 'test@example.com');
        $store->recordAttempt('10.9.9.9', 'test@example.com');

        $service = new LoginThrottleInfoService();
        $service->setRepository($store);
        $service->setFirewallsConfig(['main' => ['max_attempts' => 3, 'timeout' => 600, 'storage' => 'database']]);

        $request = Request::create('/login', 'POST', ['login_form' => ['_username' => ' Test@Example.com']], server: ['REMOTE_ADDR' => '192.168.1.1']);
        $result = $service->getAttemptInfo('main', $request);

        $this->assertSame(2, $result['current_attempts']);
        $this->assertSame(3, $result['max_attempts']);
        $this->assertSame(1, $result['remaining_attempts']);
        $this->assertFalse($result['is_blocked']);
        $this->assertNull($result['retry_after']);
        $this->assertSame('username', $result['tracking_type']);

        $store->recordAttempt('192.168.1.1', 'test@example.com');
        $blocked = $service->getAttemptInfo('main', Request::create('/login', 'GET', server: ['REMOTE_ADDR' => '192.168.1.1']), 'TEST@example.com');
        $this->assertTrue($blocked['is_blocked']);
        $this->assertSame(0, $blocked['remaining_attempts']);
        $this->assertEquals($store->now->modify('+600 seconds'), $blocked['retry_after']);
    }

    public function testDatabaseFiguresApplyThePerIpCapAndTheLegacyFlatConfig(): void
    {
        $store = new InMemoryLoginAttemptStore();
        foreach (['a', 'b'] as $username) {
            $store->recordAttempt('192.168.1.1', $username);
        }

        $service = new LoginThrottleInfoService();
        $service->setRepository($store);
        $service->setFirewallsConfig(['max_attempts' => 5, 'interval' => '5 minutes', 'storage' => 'database', 'firewall' => 'main', 'max_attempts_per_ip' => 2]);

        $result = $service->getAttemptInfo('main', Request::create('/login', 'POST', ['email' => 'c'], server: ['REMOTE_ADDR' => '192.168.1.1']));

        $this->assertSame(0, $result['current_attempts']);
        $this->assertSame(5, $result['max_attempts']);
        $this->assertTrue($result['is_blocked']);
        $this->assertSame(0, $result['remaining_attempts']);
        $this->assertSame('username', $result['tracking_type']);
    }

    public function testGetAttemptInfoWithCacheStorage(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'cache',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(0, $result['current_attempts']);
        $this->assertSame(3, $result['max_attempts']);
        $this->assertSame(0, $result['remaining_attempts']);
        $this->assertFalse($result['is_blocked']);
        $this->assertSame('username', $result['tracking_type']);
    }

    public function testGetAttemptInfoWithIntervalString(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'interval' => '10 minutes',
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with($this->anything(), 600) // 10 minutes = 600 seconds
            ->willReturn(0);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(0, $result['current_attempts']);
    }

    public function testGetAttemptInfoWithEmptyUsername(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST', ['_username' => '']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with('192.168.1.1', 600)
            ->willReturn(1);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame('ip', $result['tracking_type']);
    }

    public function testGetAttemptInfoWithDifferentIntervalFormats(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'interval' => '1 hour',
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with('192.168.1.1', 3600) // 1 hour = 3600 seconds
            ->willReturn(0);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(0, $result['current_attempts']);
    }

    public function testGetAttemptInfoWithInvalidIntervalDefaultsTo10Minutes(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'interval' => 'invalid format',
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with('192.168.1.1', 600) // Defaults to 10 minutes = 600 seconds
            ->willReturn(0);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(0, $result['current_attempts']);
    }

    public function testGetAttemptInfoWithNonExistentFirewall(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');

        $result = $this->service->getAttemptInfo('nonexistent', $request);

        $this->assertSame(0, $result['current_attempts']);
        $this->assertSame(0, $result['max_attempts']);
    }

    public function testGetAttemptInfoWithSingleFirewallConfigNonMatching(): void
    {
        $config = [
            'max_attempts' => 5,
            'timeout' => 300,
            'storage' => 'database',
            'firewall' => 'main',
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');

        $result = $this->service->getAttemptInfo('other', $request);

        $this->assertSame(0, $result['current_attempts']);
    }

    public function testGetTimeoutSecondsWithVariousUnits(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'interval' => '30 seconds',
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $callCount = 0;
        $this->repository
            ->expects($this->exactly(2))
            ->method('countAttemptsByIp')
            ->willReturnCallback(function ($ip, $seconds) use (&$callCount): int {
                ++$callCount;
                if (1 === $callCount) {
                    $this->assertEquals('192.168.1.1', $ip);
                    $this->assertEquals(30, $seconds);
                } else {
                    $this->assertEquals('192.168.1.1', $ip);
                    $this->assertEquals(300, $seconds); // 5 minutes = 300 seconds
                }

                return 0;
            });

        $this->service->getAttemptInfo('main', $request);

        // Test minutes
        $config['main']['interval'] = '5 minutes';
        $this->service->setFirewallsConfig($config);

        $this->service->getAttemptInfo('main', $request);
    }

    public function testGetAttemptInfoWithDatabaseStorageWithoutRepository(): void
    {
        $service = new LoginThrottleInfoService();
        $service->setFirewallsConfig([
            'main' => [
                'max_attempts' => 5,
                'timeout' => 600,
                'storage' => 'database',
            ],
        ]);

        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $result = $service->getAttemptInfo('main', $request);

        $this->assertSame(0, $result['current_attempts']);
        $this->assertSame(5, $result['max_attempts']);
        $this->assertSame(5, $result['remaining_attempts']);
        $this->assertFalse($result['is_blocked']);
        $this->assertNull($result['retry_after']);
        $this->assertSame('username', $result['tracking_type']);
    }

    public function testGetTimeoutSecondsWithExtendedUnits(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'interval' => '2 hours',
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $intervals = [
            '2 hours' => 7200,
            '1 day' => 86400,
            '1 week' => 604800,
            '1 month' => 2592000,
            '1 year' => 31536000,
        ];

        $callCount = 0;
        $this->repository
            ->expects($this->exactly(\count($intervals)))
            ->method('countAttemptsByIp')
            ->willReturnCallback(function ($ip, $seconds) use (&$callCount, $intervals): int {
                $expected = array_values($intervals)[$callCount];
                $this->assertSame($expected, $seconds);
                ++$callCount;

                return 0;
            });

        foreach (array_keys($intervals) as $interval) {
            $config['main']['interval'] = $interval;
            unset($config['main']['timeout']);
            $this->service->setFirewallsConfig($config);
            $this->service->getAttemptInfo('main', $request);
        }
    }

    public function testGetAttemptInfoWithCacheStorageNoUsername(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'cache',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST');

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame('ip', $result['tracking_type']);
    }
}
