<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Command;

use Nowo\LoginThrottleBundle\Command\CleanupLoginAttemptsCommand;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupLoginAttemptsCommandTest extends TestCase
{
    public function testSucceedsWithoutRepository(): void
    {
        $tester = new CommandTester(new CleanupLoginAttemptsCommand(null, 3600));
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('not available', $tester->getDisplay());
    }

    public function testDeletesViaRepository(): void
    {
        $repository = $this->createMock(LoginAttemptRepositoryInterface::class);
        $repository->expects(self::once())->method('cleanup')->with(7200)->willReturn(3);

        $tester = new CommandTester(new CleanupLoginAttemptsCommand($repository, 3600));
        $status = $tester->execute(['--watch-period' => '7200']);

        self::assertSame(0, $status);
        self::assertStringContainsString('Deleted 3', $tester->getDisplay());
    }

    public function testDryRunDoesNotDelete(): void
    {
        $repository = $this->createMock(LoginAttemptRepositoryInterface::class);
        $repository->expects(self::never())->method('cleanup');
        $repository->expects(self::once())->method('countOlderThan')->with(3600)->willReturn(5);

        $tester = new CommandTester(new CleanupLoginAttemptsCommand($repository, 3600));
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $status);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
        self::assertStringContainsString('would delete 5', $tester->getDisplay());
    }

    public function testRejectsInvalidWatchPeriod(): void
    {
        $repository = $this->createMock(LoginAttemptRepositoryInterface::class);
        $tester = new CommandTester(new CleanupLoginAttemptsCommand($repository, 3600));
        $status = $tester->execute(['--watch-period' => '0']);

        self::assertSame(1, $status);
    }

    /**
     * 3.15: rows a database limiter still counts (younger than its timeout) are never deleted, whatever the option.
     */
    public function testNeverDeletesAttemptsADatabaseLimiterStillCounts(): void
    {
        $repository = $this->createMock(LoginAttemptRepositoryInterface::class);
        $repository->expects(self::once())->method('cleanup')->with(5400)->willReturn(0);
        $firewalls = [
            'main' => ['storage' => 'database', 'timeout' => 600],
            'api' => ['storage' => 'database', 'timeout' => 5400],
            'admin' => ['storage' => 'cache', 'timeout' => 86400],
            'broken' => 'not a firewall',
        ];

        $tester = new CommandTester(new CleanupLoginAttemptsCommand($repository, 3600, $firewalls));
        $status = $tester->execute(['--watch-period' => '60']);

        self::assertSame(0, $status);
        self::assertStringContainsString('Keeping the attempts of the last 5400 seconds', $tester->getDisplay());
    }

    public function testAWatchPeriodAboveEveryTimeoutIsKept(): void
    {
        $repository = $this->createMock(LoginAttemptRepositoryInterface::class);
        $repository->expects(self::once())->method('countOlderThan')->with(3600)->willReturn(0);

        $tester = new CommandTester(new CleanupLoginAttemptsCommand($repository, 3600, ['main' => ['storage' => 'database', 'timeout' => 600]]));
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $status);
        self::assertStringNotContainsString('Keeping', $tester->getDisplay());
    }
}
