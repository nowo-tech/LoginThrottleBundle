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
}
