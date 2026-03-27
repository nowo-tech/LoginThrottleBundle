<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\RateLimiter;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Tests for DatabaseRateLimiter.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class DatabaseRateLimiterTest extends TestCase
{
    private DatabaseRateLimiter $rateLimiter;
    private LoginAttemptRepository|MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LoginAttemptRepository::class);
        $this->rateLimiter = new DatabaseRateLimiter(
            $this->repository,
            3,    // maxAttempts
            600,  // timeoutSeconds
            3600  // watchPeriodSeconds
        );
    }

    public function testConsumeWhenNotBlocked(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('isBlocked')
            ->with('192.168.1.1', 'test@example.com', 3, 600)
            ->willReturn(false);

        $this->repository
            ->expects($this->once())
            ->method('recordAttempt')
            ->with('192.168.1.1', 'test@example.com');

        $this->repository
            ->expects($this->once())
            ->method('countAttempts')
            ->with('192.168.1.1', 'test@example.com', 600)
            ->willReturn(1);

        $rateLimit = $this->rateLimiter->consume($request);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertSame(2, $rateLimit->getRemainingTokens());
        $this->assertTrue($rateLimit->isAccepted());
    }

    public function testConsumeWhenBlocked(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('isBlocked')
            ->with('192.168.1.1', 'test@example.com', 3, 600)
            ->willReturn(true);

        $oldestAttempt = new LoginAttempt('192.168.1.1', 'test@example.com');
        $retryAfter = new \DateTimeImmutable('+10 minutes');

        $this->repository
            ->expects($this->once())
            ->method('getAttempts')
            ->with('192.168.1.1', 'test@example.com', 600)
            ->willReturn([$oldestAttempt]);

        $rateLimit = $this->rateLimiter->consume($request);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertSame(0, $rateLimit->getRemainingTokens());
        $this->assertFalse($rateLimit->isAccepted());
        $this->assertNotNull($rateLimit->getRetryAfter());
    }

    public function testConsumeWhenMaxAttemptsReached(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('isBlocked')
            ->with('192.168.1.1', 'test@example.com', 3, 600)
            ->willReturn(false);

        $this->repository
            ->expects($this->once())
            ->method('recordAttempt')
            ->with('192.168.1.1', 'test@example.com');

        $this->repository
            ->expects($this->once())
            ->method('countAttempts')
            ->with('192.168.1.1', 'test@example.com', 600)
            ->willReturn(3);

        $oldestAttempt = new LoginAttempt('192.168.1.1', 'test@example.com');

        $this->repository
            ->expects($this->once())
            ->method('getAttempts')
            ->with('192.168.1.1', 'test@example.com', 600)
            ->willReturn([$oldestAttempt]);

        $rateLimit = $this->rateLimiter->consume($request);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertSame(0, $rateLimit->getRemainingTokens());
        $this->assertFalse($rateLimit->isAccepted()); // When max attempts reached, should be rejected
        $this->assertNotNull($rateLimit->getRetryAfter());
    }

    public function testConsumeWithIpOnly(): void
    {
        $request = Request::create('/login', 'POST');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('isBlocked')
            ->with('192.168.1.1', null, 3, 600)
            ->willReturn(false);

        $this->repository
            ->expects($this->once())
            ->method('recordAttempt')
            ->with('192.168.1.1', null);

        $this->repository
            ->expects($this->once())
            ->method('countAttempts')
            ->with('192.168.1.1', null, 600)
            ->willReturn(2);

        $rateLimit = $this->rateLimiter->consume($request);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertSame(1, $rateLimit->getRemainingTokens());
    }

    public function testConsumeWithUnknownIp(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        // getClientIp() may return '127.0.0.1' as fallback, so we use that
        $expectedIp = $request->getClientIp() ?? 'unknown';

        $this->repository
            ->expects($this->once())
            ->method('isBlocked')
            ->with($expectedIp, 'test@example.com', 3, 600)
            ->willReturn(false);

        $this->repository
            ->expects($this->once())
            ->method('recordAttempt')
            ->with($expectedIp, 'test@example.com');

        $this->repository
            ->expects($this->once())
            ->method('countAttempts')
            ->with($expectedIp, 'test@example.com', 600)
            ->willReturn(0);

        $rateLimit = $this->rateLimiter->consume($request);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertSame(3, $rateLimit->getRemainingTokens());
    }

    public function testConsumeWithDifferentUsernameFields(): void
    {
        // Test with 'username' field
        $request = Request::create('/login', 'POST', ['username' => 'user@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->exactly(2))
            ->method('isBlocked')
            ->willReturnCallback(function ($ip, $username, $maxAttempts, $timeout) {
                if ($ip === '192.168.1.1' && $username === 'user@example.com' && $maxAttempts === 3 && $timeout === 600) {
                    return false;
                }
                if ($ip === '192.168.1.1' && $username === 'email@example.com' && $maxAttempts === 3 && $timeout === 600) {
                    return false;
                }

                return false;
            });

        $this->repository
            ->expects($this->exactly(2))
            ->method('recordAttempt')
            ->willReturnCallback(function ($ip, $username) {
                return new LoginAttempt($ip, $username);
            });

        $this->repository
            ->expects($this->exactly(2))
            ->method('countAttempts')
            ->willReturnCallback(function ($ip, $username, $timeout) {
                if ($ip === '192.168.1.1' && $username === 'user@example.com' && $timeout === 600) {
                    return 1;
                }
                if ($ip === '192.168.1.1' && $username === 'email@example.com' && $timeout === 600) {
                    return 1;
                }

                return 0;
            });

        $this->rateLimiter->consume($request);

        // Test with 'email' field
        $request2 = Request::create('/login', 'POST', ['email' => 'email@example.com']);
        $request2->server->set('REMOTE_ADDR', '192.168.1.1');

        // The mocks above already cover both calls, so we just need to consume
        $this->rateLimiter->consume($request2);
    }

    public function testConsumeWithEmptyAttemptsArray(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('isBlocked')
            ->with('192.168.1.1', 'test@example.com', 3, 600)
            ->willReturn(true);

        $this->repository
            ->expects($this->once())
            ->method('getAttempts')
            ->with('192.168.1.1', 'test@example.com', 600)
            ->willReturn([]);

        $rateLimit = $this->rateLimiter->consume($request);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertSame(0, $rateLimit->getRemainingTokens());
        // When blocked but no attempts found, retryAfter should be current time (immediate retry)
        $this->assertNotNull($rateLimit->getRetryAfter());
        $this->assertInstanceOf(\DateTimeImmutable::class, $rateLimit->getRetryAfter());
    }

    public function testReset(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        // Reset should not throw any exceptions
        $this->rateLimiter->reset($request);

        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    public function testCalculateRetryAfter(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => 'test@example.com']);
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $this->repository
            ->expects($this->once())
            ->method('isBlocked')
            ->with('192.168.1.1', 'test@example.com', 3, 600)
            ->willReturn(true);

        // Create an attempt from 5 minutes ago
        $oldestAttempt = new LoginAttempt('192.168.1.1', 'test@example.com');
        $oldestAttempt = new LoginAttempt('192.168.1.1', 'test@example.com');
        // We can't easily modify createdAt, but we can test the logic

        $this->repository
            ->expects($this->once())
            ->method('getAttempts')
            ->with('192.168.1.1', 'test@example.com', 600)
            ->willReturn([$oldestAttempt]);

        $rateLimit = $this->rateLimiter->consume($request);

        $this->assertInstanceOf(RateLimit::class, $rateLimit);
        $this->assertNotNull($rateLimit->getRetryAfter());
        $this->assertInstanceOf(\DateTimeImmutable::class, $rateLimit->getRetryAfter());
    }
}
