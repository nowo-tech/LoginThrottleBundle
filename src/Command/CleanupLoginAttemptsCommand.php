<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Command;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes old LoginAttempt rows when using database storage.
 */
#[AsCommand(
    name: 'nowo:login-throttle:cleanup',
    description: 'Delete login attempt rows older than the configured watch period',
)]
final class CleanupLoginAttemptsCommand extends Command
{
    public function __construct(
        private readonly ?LoginAttemptRepositoryInterface $repository = null,
        private readonly int $defaultWatchPeriod = 3600,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'watch-period',
                null,
                InputOption::VALUE_REQUIRED,
                'Age threshold in seconds (defaults to nowo_login_throttle.watch_period)',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many rows would be deleted without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->repository instanceof LoginAttemptRepositoryInterface) {
            $io->warning('Login attempt repository is not available (database storage may be disabled). Nothing to clean.');

            return self::SUCCESS;
        }

        $watchPeriod = $input->getOption('watch-period');
        $seconds     = is_numeric($watchPeriod) ? (int) $watchPeriod : $this->defaultWatchPeriod;
        if ($seconds < 1) {
            $io->error('watch-period must be a positive integer.');

            return self::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $count = $this->repository->countOlderThan($seconds);
            $io->note(sprintf(
                'Dry-run: would delete %d LoginAttempt row(s) older than %d seconds (use without --dry-run to apply).',
                $count,
                $seconds,
            ));

            return self::SUCCESS;
        }

        $deleted = $this->repository->cleanup($seconds);
        $io->success(sprintf('Deleted %d login attempt row(s) older than %d seconds.', $deleted, $seconds));

        return self::SUCCESS;
    }
}
