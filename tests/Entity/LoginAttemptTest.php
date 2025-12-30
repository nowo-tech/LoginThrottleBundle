<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Entity;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LoginAttempt entity.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoginAttemptTest extends TestCase
{
    public function testConstructorWithIpOnly(): void
    {
        $attempt = new LoginAttempt('192.168.1.1');

        $this->assertSame('192.168.1.1', $attempt->getIpAddress());
        $this->assertNull($attempt->getUsername());
        $this->assertInstanceOf(\DateTimeImmutable::class, $attempt->getCreatedAt());
        $this->assertFalse($attempt->isBlocked());
        $this->assertNull($attempt->getId());
    }

    public function testConstructorWithIpAndUsername(): void
    {
        $attempt = new LoginAttempt('192.168.1.1', 'test@example.com');

        $this->assertSame('192.168.1.1', $attempt->getIpAddress());
        $this->assertSame('test@example.com', $attempt->getUsername());
        $this->assertInstanceOf(\DateTimeImmutable::class, $attempt->getCreatedAt());
        $this->assertFalse($attempt->isBlocked());
    }

    public function testGetId(): void
    {
        $attempt = new LoginAttempt('192.168.1.1');
        $this->assertNull($attempt->getId());
    }

    public function testGetIpAddress(): void
    {
        $attempt = new LoginAttempt('10.0.0.1', 'user@example.com');
        $this->assertSame('10.0.0.1', $attempt->getIpAddress());
    }

    public function testGetUsername(): void
    {
        $attempt = new LoginAttempt('192.168.1.1', 'admin@example.com');
        $this->assertSame('admin@example.com', $attempt->getUsername());

        $attempt2 = new LoginAttempt('192.168.1.1');
        $this->assertNull($attempt2->getUsername());
    }

    public function testGetCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $attempt = new LoginAttempt('192.168.1.1');
        $after = new \DateTimeImmutable();

        $createdAt = $attempt->getCreatedAt();
        $this->assertInstanceOf(\DateTimeImmutable::class, $createdAt);
        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
    }

    public function testIsBlockedDefault(): void
    {
        $attempt = new LoginAttempt('192.168.1.1');
        $this->assertFalse($attempt->isBlocked());
    }

    public function testSetBlocked(): void
    {
        $attempt = new LoginAttempt('192.168.1.1');

        $this->assertFalse($attempt->isBlocked());

        $result = $attempt->setBlocked(true);
        $this->assertTrue($attempt->isBlocked());
        $this->assertSame($attempt, $result); // Test fluent interface

        $attempt->setBlocked(false);
        $this->assertFalse($attempt->isBlocked());
    }
}

