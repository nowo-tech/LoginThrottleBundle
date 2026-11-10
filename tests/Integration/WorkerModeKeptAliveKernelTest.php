<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\DependencyInjection\NowoLoginThrottleExtension;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Tests\Integration\Kernel\MappingTestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * FrankenPHP worker mode with the kernel kept alive (FRANKENPHP_RESET_KERNEL=false, no services resetter): one booted
 * kernel and the same limiter instance handle 1,200 failed logins of two users. Memory stays flat, the shared
 * EntityManager never holds an attempt, and each user's bucket only counts and clears its own attempts.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class WorkerModeKeptAliveKernelTest extends TestCase
{
    private const REQUESTS = 1200;

    private const WARMUP = 200;

    protected function tearDown(): void
    {
        (new Filesystem())->remove(MappingTestKernel::baseDir());
    }

    public function testAKeptAliveKernelThrottlesManyLoginsWithFlatMemoryAndNoLeak(): void
    {
        $kernel = new MappingTestKernel(['storage' => 'database', 'max_count_attempts' => 100000, 'timeout' => 600, 'watch_period' => 3600], 'worker-kept-alive');
        $kernel->boot();

        try {
            $registry = $kernel->getContainer()->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $registry);
            $entityManager = $registry->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

            $limiter = $kernel->getContainer()->get(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID);
            self::assertInstanceOf(DatabaseRateLimiter::class, $limiter);

            $baseline = 0;
            for ($i = 0; $i < self::REQUESTS; ++$i) {
                if (self::WARMUP === $i) {
                    gc_collect_cycles();
                    $baseline = memory_get_usage();
                }
                $limiter->consume($this->login(0 === $i % 2 ? 'alice' : 'bob'));
                self::assertSame(0, $entityManager->getUnitOfWork()->size());
            }
            gc_collect_cycles();
            self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory must stay flat across requests');

            $connection = $entityManager->getConnection();
            $count = static fn (string $user): int => (int) $connection->fetchOne('SELECT COUNT(*) FROM login_attempts WHERE username = ?', [$user]);
            self::assertSame(600, $count('alice'));
            self::assertSame(600, $count('bob'));

            $limiter->reset($this->login('alice'));
            self::assertSame(0, $count('alice'));
            self::assertSame(600, $count('bob'), 'clearing one user leaves the other untouched');
        } finally {
            $kernel->shutdown();
        }
    }

    private function login(string $username): Request
    {
        return Request::create('/login', 'POST', ['_username' => $username], server: ['REMOTE_ADDR' => 'alice' === $username ? '10.0.0.1' : '10.0.0.2']);
    }
}
