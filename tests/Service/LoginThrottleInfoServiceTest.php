<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Service;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
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
    private LoginAttemptRepository|MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LoginAttemptRepository::class);
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

    public function testGetAttemptInfoWithDatabaseStorageByUsername(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByUsername')
            ->with('test@example.com', 600)
            ->willReturn(2);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(2, $result['current_attempts']);
        $this->assertSame(3, $result['max_attempts']);
        $this->assertSame(1, $result['remaining_attempts']);
        $this->assertFalse($result['is_blocked']);
        $this->assertSame('username', $result['tracking_type']);
    }

    public function testGetAttemptInfoWithDatabaseStorageByIp(): void
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
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with('192.168.1.1', 600)
            ->willReturn(1);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(1, $result['current_attempts']);
        $this->assertSame(3, $result['max_attempts']);
        $this->assertSame(2, $result['remaining_attempts']);
        $this->assertFalse($result['is_blocked']);
        $this->assertSame('ip', $result['tracking_type']);
    }

    public function testGetAttemptInfoWithBlockedAccountByUsername(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByUsername')
            ->with('test@example.com', 600)
            ->willReturn(3);

        $oldestAttempt = new LoginAttempt('192.168.1.1', 'test@example.com');
        $retryAfter = new \DateTimeImmutable('+10 minutes');

        $this->repository
            ->expects($this->once())
            ->method('getAttempts')
            ->with('', 'test@example.com', 600)
            ->willReturn([$oldestAttempt]);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(3, $result['current_attempts']);
        $this->assertTrue($result['is_blocked']);
        $this->assertSame('username', $result['tracking_type']);
    }

    public function testGetAttemptInfoWithBlockedAccountByIp(): void
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
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with('192.168.1.1', 600)
            ->willReturn(3);

        $oldestAttempt = new LoginAttempt('192.168.1.1', null);

        $this->repository
            ->expects($this->once())
            ->method('getAttempts')
            ->with('192.168.1.1', null, 600)
            ->willReturn([$oldestAttempt]);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(3, $result['current_attempts']);
        $this->assertTrue($result['is_blocked']);
        $this->assertSame('ip', $result['tracking_type']);
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

    public function testGetAttemptInfoWithSingleFirewallConfig(): void
    {
        $config = [
            'max_attempts' => 5,
            'timeout' => 300,
            'storage' => 'database',
            'firewall' => 'main',
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByUsername')
            ->with('test@example.com', 300)
            ->willReturn(2);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertSame(2, $result['current_attempts']);
        $this->assertSame(5, $result['max_attempts']);
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

    public function testExtractUsernameFromRequest(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        // Test with _username
        $request = Request::create('/login', 'POST', ['_username' => 'user1@example.com']);
        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByUsername')
            ->with('user1@example.com', 600)
            ->willReturn(0);

        $this->service->getAttemptInfo('main', $request);

        // Test with email
        $request = Request::create('/login', 'POST', ['email' => 'user2@example.com']);
        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByUsername')
            ->with('user2@example.com', 600)
            ->willReturn(0);

        $this->service->getAttemptInfo('main', $request);
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

    public function testGetAttemptInfoWithBlockedAccountEmptyAttemptsArray(): void
    {
        $config = [
            'main' => [
                'max_attempts' => 3,
                'timeout' => 600,
                'storage' => 'database',
            ],
        ];
        $this->service->setFirewallsConfig($config);

        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByUsername')
            ->with('test@example.com', 600)
            ->willReturn(3);

        $this->repository
            ->expects($this->once())
            ->method('getAttempts')
            ->with('', 'test@example.com', 600)
            ->willReturn([]);

        $result = $this->service->getAttemptInfo('main', $request);

        $this->assertTrue($result['is_blocked']);
        $this->assertNull($result['retry_after']);
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

        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with('192.168.1.1', 30)
            ->willReturn(0);

        $this->service->getAttemptInfo('main', $request);

        // Test minutes
        $config['main']['interval'] = '5 minutes';
        $this->service->setFirewallsConfig($config);
        $this->repository
            ->expects($this->once())
            ->method('countAttemptsByIp')
            ->with('192.168.1.1', 300) // 5 minutes = 300 seconds
            ->willReturn(0);

        $this->service->getAttemptInfo('main', $request);
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
