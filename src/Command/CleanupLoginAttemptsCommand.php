<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Command;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prunes old LoginAttempt rows when using database storage.
 *
 * Since 3.15 it never deletes a row a database limiter still counts: the age threshold is at least the longest
 * {@code timeout} of the database firewalls (a {@code --watch-period} or a {@code watch_period} below it would have
 * cleared lockouts in progress, letting a locked-out client try again at once).
 */
#[AsCommand(
    name: 'nowo:login-throttle:cleanup',
    description: 'Delete login attempt rows older than the configured watch period',
)]
final class CleanupLoginAttemptsCommand extends Command
{
    /**
     * @param LoginAttemptRepositoryInterface|LoginAttemptStoreInterface|null $repository         The attempt store (null without database storage)
     * @param int                                                             $defaultWatchPeriod Age threshold in seconds when no option is given
     * @param array<array-key, mixed>                                         $firewalls          The {@code nowo_login_throttle.firewalls} parameter: the longest database {@code timeout} is the lowest threshold (3.15)
     */
    public function __construct(
        private readonly LoginAttemptRepositoryInterface|LoginAttemptStoreInterface|null $repository = null,
        private readonly int $defaultWatchPeriod = 3600,
        private readonly array $firewalls = [],
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

        if (null === $this->repository) {
            $io->warning('Login attempt repository is not available (database storage may be disabled). Nothing to clean.');

            return self::SUCCESS;
        }

        $watchPeriod = $input->getOption('watch-period');
        $seconds = is_numeric($watchPeriod) ? (int) $watchPeriod : $this->defaultWatchPeriod;
        if ($seconds < 1) {
            $io->error('watch-period must be a positive integer.');

            return self::FAILURE;
        }

        $longestTimeout = $this->longestDatabaseTimeout();
        if ($seconds < $longestTimeout) {
            $io->note(\sprintf('Keeping the attempts of the last %d seconds: a database limiter still counts them (its timeout is %d seconds).', $longestTimeout, $longestTimeout));
            $seconds = $longestTimeout;
        }

        if ($input->getOption('dry-run')) {
            $count = $this->repository->countOlderThan($seconds);
            $io->note(\sprintf(
                'Dry-run: would delete %d LoginAttempt row(s) older than %d seconds (use without --dry-run to apply).',
                $count,
                $seconds,
            ));

            return self::SUCCESS;
        }

        $deleted = $this->repository->cleanup($seconds);
        $io->success(\sprintf('Deleted %d login attempt row(s) older than %d seconds.', $deleted, $seconds));

        return self::SUCCESS;
    }

    /**
     * The longest {@code timeout} (seconds) of the firewalls with database storage; 0 without any.
     */
    private function longestDatabaseTimeout(): int
    {
        $longest = 0;
        foreach ($this->firewalls as $firewall) {
            if (\is_array($firewall) && 'database' === ($firewall['storage'] ?? null) && is_numeric($firewall['timeout'] ?? null)) {
                $longest = max($longest, (int) $firewall['timeout']);
            }
        }

        return $longest;
    }
}
