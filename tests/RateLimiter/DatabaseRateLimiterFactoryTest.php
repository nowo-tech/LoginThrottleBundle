<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\RateLimiter;

use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiterFactory;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DatabaseRateLimiterFactory.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class DatabaseRateLimiterFactoryTest extends TestCase
{
    private DatabaseRateLimiterFactory $factory;
    private LoginAttemptRepository|MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LoginAttemptRepository::class);
        $this->factory = new DatabaseRateLimiterFactory(
            $this->repository,
            3,    // maxAttempts
            600,  // timeoutSeconds
            3600  // watchPeriodSeconds
        );
    }

    public function testCreate(): void
    {
        $rateLimiter = $this->factory->create();

        $this->assertInstanceOf(DatabaseRateLimiter::class, $rateLimiter);
    }

    public function testCreateReturnsNewInstance(): void
    {
        $rateLimiter1 = $this->factory->create();
        $rateLimiter2 = $this->factory->create();

        $this->assertNotSame($rateLimiter1, $rateLimiter2);
        $this->assertInstanceOf(DatabaseRateLimiter::class, $rateLimiter1);
        $this->assertInstanceOf(DatabaseRateLimiter::class, $rateLimiter2);
    }

    public function testFactoryWithDifferentConfigurations(): void
    {
        $factory1 = new DatabaseRateLimiterFactory($this->repository, 5, 300, 1800);
        $factory2 = new DatabaseRateLimiterFactory($this->repository, 10, 1200, 7200);

        $rateLimiter1 = $factory1->create();
        $rateLimiter2 = $factory2->create();

        $this->assertInstanceOf(DatabaseRateLimiter::class, $rateLimiter1);
        $this->assertInstanceOf(DatabaseRateLimiter::class, $rateLimiter2);
        $this->assertNotSame($rateLimiter1, $rateLimiter2);
    }
}
