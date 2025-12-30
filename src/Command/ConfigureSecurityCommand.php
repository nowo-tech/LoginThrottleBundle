<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Command to configure security.yaml with login_throttling settings.
 *
 * This command automatically adds or updates the login_throttling configuration
 * in security.yaml based on the bundle configuration.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
#[AsCommand(
    name: 'nowo:login-throttle:configure-security',
    description: 'Configures security.yaml with login_throttling settings'
)]
class ConfigureSecurityCommand extends Command
{
    /**
     * Constructor.
     *
     * @param string|null $projectDir The project directory
     */
    public function __construct(
        private readonly ?string $projectDir = null
    ) {
        parent::__construct();
    }

    /**
     * Configures the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force update even if login_throttling is already configured')
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command configures your <comment>security.yaml</comment> file
with the login_throttling settings based on your bundle configuration.

<info>php %command.full_name%</info>

This command will:
1. Read your <comment>nowo_login_throttle.yaml</comment> configuration
2. Add or update <comment>login_throttling</comment> in your <comment>security.yaml</comment>
3. Configure all firewalls specified in your bundle configuration

For single firewall configuration, it uses the <comment>firewall</comment> option.
For multiple firewalls, it processes each firewall in the <comment>firewalls</comment> section.

If <comment>login_throttling</comment> is already configured, the command will skip
the update unless you use the <comment>--force</comment> option.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem();

        $projectDir = $this->projectDir;
        if (null === $projectDir) {
            // Try to get from kernel or use current working directory
            $projectDir = getcwd() ?: '.';
        }
        $securityYamlPath = $projectDir . '/config/packages/security.yaml';
        $bundleConfigPath = $projectDir . '/config/packages/nowo_login_throttle.yaml';

        // Check if bundle config exists
        if (!file_exists($bundleConfigPath)) {
            $io->error(sprintf('Bundle configuration file not found: %s', $bundleConfigPath));
            $io->note('Please ensure the bundle is properly installed and configured.');

            return Command::FAILURE;
        }

        // Load bundle configuration
        $bundleConfig = Yaml::parseFile($bundleConfigPath);
        $throttleConfig = $bundleConfig['nowo_login_throttle'] ?? [];

        // Check if using multiple firewalls configuration
        if (!empty($throttleConfig['firewalls']) && is_array($throttleConfig['firewalls'])) {
            return $this->configureMultipleFirewalls($io, $filesystem, $securityYamlPath, $throttleConfig['firewalls'], $input->getOption('force'));
        }

        // Single firewall configuration (backward compatibility)
        if (!($throttleConfig['enabled'] ?? true)) {
            $io->warning('Login throttling is disabled in bundle configuration. Skipping security.yaml update.');

            return Command::SUCCESS;
        }

        $maxAttempts = $throttleConfig['max_count_attempts'] ?? 3;
        $timeout = $throttleConfig['timeout'] ?? 600;
        $firewall = $throttleConfig['firewall'] ?? 'main';
        $storage = $throttleConfig['storage'] ?? 'cache';
        $rateLimiter = $throttleConfig['rate_limiter'] ?? null;
        $cachePool = $throttleConfig['cache_pool'] ?? 'cache.rate_limiter';
        $lockFactory = $throttleConfig['lock_factory'] ?? null;

        // Use database rate limiter if storage is database and no custom rate limiter is set
        if ($storage === 'database' && null === $rateLimiter) {
            $rateLimiter = 'nowo_login_throttle.database_rate_limiter';
        }

        return $this->configureSingleFirewall($io, $filesystem, $securityYamlPath, $firewall, [
            'max_attempts' => $maxAttempts,
            'timeout' => $timeout,
            'rate_limiter' => $rateLimiter,
            'cache_pool' => $cachePool,
            'lock_factory' => $lockFactory,
        ], $input->getOption('force'));
    }

    /**
     * Configures a single firewall.
     *
     * @param SymfonyStyle $io Symfony style output
     * @param Filesystem $filesystem Filesystem component
     * @param string $securityYamlPath Path to security.yaml
     * @param string $firewall Firewall name
     * @param array<string, mixed> $config Firewall configuration
     * @param bool $force Force update
     *
     * @return int Command exit code
     */
    private function configureSingleFirewall(SymfonyStyle $io, Filesystem $filesystem, string $securityYamlPath, string $firewall, array $config, bool $force): int
    {
        // Convert timeout to interval string
        $interval = $this->secondsToInterval($config['timeout']);

        // Load or create security.yaml
        if (!file_exists($securityYamlPath)) {
            $io->warning(sprintf('security.yaml not found at: %s', $securityYamlPath));
            $io->note('Please create a basic security.yaml file first.');

            return Command::FAILURE;
        }

        $securityConfig = Yaml::parseFile($securityYamlPath) ?? [];

        // Check if login_throttling is already configured
        $firewallConfig = $securityConfig['security']['firewalls'][$firewall] ?? [];
        if (isset($firewallConfig['login_throttling']) && !$force) {
            $io->info(sprintf('login_throttling is already configured for firewall "%s".', $firewall));
            $io->note('Use --force to update the existing configuration.');

            return Command::SUCCESS;
        }

        // Ensure security.firewalls structure exists
        if (!isset($securityConfig['security'])) {
            $securityConfig['security'] = [];
        }
        if (!isset($securityConfig['security']['firewalls'])) {
            $securityConfig['security']['firewalls'] = [];
        }
        if (!isset($securityConfig['security']['firewalls'][$firewall])) {
            $securityConfig['security']['firewalls'][$firewall] = [];
        }

        // Add or update login_throttling
        $loginThrottling = [
            'max_attempts' => $config['max_attempts'],
            'interval' => $interval,
        ];

        if (null !== ($config['rate_limiter'] ?? null)) {
            $loginThrottling['limiter'] = $config['rate_limiter'];
        }

        if (null !== ($config['cache_pool'] ?? null) && 'cache.rate_limiter' !== ($config['cache_pool'] ?? 'cache.rate_limiter')) {
            $loginThrottling['cache_pool'] = $config['cache_pool'];
        }

        if (null !== ($config['lock_factory'] ?? null)) {
            $loginThrottling['lock_factory'] = $config['lock_factory'];
        }

        $securityConfig['security']['firewalls'][$firewall]['login_throttling'] = $loginThrottling;

        // Write updated security.yaml
        try {
            $yaml = Yaml::dump($securityConfig, 10, 2);
            $filesystem->dumpFile($securityYamlPath, $yaml);
            $io->success(sprintf('Successfully configured login_throttling in security.yaml for firewall "%s"', $firewall));
            $tableData = [
                ['Firewall', $firewall],
                ['Max Attempts', (string) $config['max_attempts']],
                ['Interval', $interval],
            ];

            if (null !== ($config['rate_limiter'] ?? null)) {
                $tableData[] = ['Rate Limiter', $config['rate_limiter']];
            }

            if (null !== ($config['cache_pool'] ?? null)) {
                $tableData[] = ['Cache Pool', $config['cache_pool']];
            }

            if (null !== ($config['lock_factory'] ?? null)) {
                $tableData[] = ['Lock Factory', $config['lock_factory']];
            }

            $io->table(['Setting', 'Value'], $tableData);
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to update security.yaml: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Configures multiple firewalls.
     *
     * @param SymfonyStyle $io Symfony style output
     * @param Filesystem $filesystem Filesystem component
     * @param string $securityYamlPath Path to security.yaml
     * @param array<string, array<string, mixed>> $firewallsConfig Firewalls configuration
     * @param bool $force Force update
     *
     * @return int Command exit code
     */
    private function configureMultipleFirewalls(SymfonyStyle $io, Filesystem $filesystem, string $securityYamlPath, array $firewallsConfig, bool $force): int
    {
        // Load or create security.yaml
        if (!file_exists($securityYamlPath)) {
            $io->warning(sprintf('security.yaml not found at: %s', $securityYamlPath));
            $io->note('Please create a basic security.yaml file first.');

            return Command::FAILURE;
        }

        $securityConfig = Yaml::parseFile($securityYamlPath) ?? [];

        // Ensure security.firewalls structure exists
        if (!isset($securityConfig['security'])) {
            $securityConfig['security'] = [];
        }
        if (!isset($securityConfig['security']['firewalls'])) {
            $securityConfig['security']['firewalls'] = [];
        }

        $configuredFirewalls = [];
        $skippedFirewalls = [];

        foreach ($firewallsConfig as $firewallName => $firewallConfig) {
            if (!($firewallConfig['enabled'] ?? true)) {
                continue;
            }

            // Check if login_throttling is already configured
            $existingFirewallConfig = $securityConfig['security']['firewalls'][$firewallName] ?? [];
            if (isset($existingFirewallConfig['login_throttling']) && !$force) {
                $skippedFirewalls[] = $firewallName;
                continue;
            }

            // Ensure firewall exists
            if (!isset($securityConfig['security']['firewalls'][$firewallName])) {
                $securityConfig['security']['firewalls'][$firewallName] = [];
            }

            $maxAttempts = $firewallConfig['max_count_attempts'] ?? 3;
            $timeout = $firewallConfig['timeout'] ?? 600;
            $watchPeriod = $firewallConfig['watch_period'] ?? 3600;
            $storage = $firewallConfig['storage'] ?? 'cache';
            $rateLimiter = $firewallConfig['rate_limiter'] ?? null;
            $cachePool = $firewallConfig['cache_pool'] ?? 'cache.rate_limiter';
            $lockFactory = $firewallConfig['lock_factory'] ?? null;

            // Use database rate limiter if storage is database and no custom rate limiter is set
            // Use the same logic as the extension to generate shared service IDs
            if ($storage === 'database' && null === $rateLimiter) {
                // Generate shared service ID based on configuration (same logic as extension)
                $limiterKey = sprintf('db-%d-%d-%d', $maxAttempts, $timeout, $watchPeriod);
                $rateLimiter = sprintf('nowo_login_throttle.database_rate_limiter.shared_%s', md5($limiterKey));
            }

            // Convert timeout to interval string
            $interval = $this->secondsToInterval($timeout);

            // Add or update login_throttling
            $loginThrottling = [
                'max_attempts' => $maxAttempts,
                'interval' => $interval,
            ];

            if (null !== $rateLimiter) {
                $loginThrottling['limiter'] = $rateLimiter;
            }

            if (null !== $cachePool && 'cache.rate_limiter' !== $cachePool) {
                $loginThrottling['cache_pool'] = $cachePool;
            }

            if (null !== $lockFactory) {
                $loginThrottling['lock_factory'] = $lockFactory;
            }

            $securityConfig['security']['firewalls'][$firewallName]['login_throttling'] = $loginThrottling;
            $configuredFirewalls[] = $firewallName;
        }

        // Write updated security.yaml
        try {
            $yaml = Yaml::dump($securityConfig, 10, 2);
            $filesystem->dumpFile($securityYamlPath, $yaml);

            if (!empty($configuredFirewalls)) {
                $io->success(sprintf('Successfully configured login_throttling for %d firewall(s): %s', count($configuredFirewalls), implode(', ', $configuredFirewalls)));

                // Show summary table
                $tableData = [];
                foreach ($firewallsConfig as $firewallName => $firewallConfig) {
                    if (!($firewallConfig['enabled'] ?? true)) {
                        continue;
                    }
                    if (in_array($firewallName, $skippedFirewalls, true)) {
                        continue;
                    }

                    $tableData[] = [
                        $firewallName,
                        (string) ($firewallConfig['max_count_attempts'] ?? 3),
                        $this->secondsToInterval($firewallConfig['timeout'] ?? 600),
                        $firewallConfig['storage'] ?? 'cache',
                    ];
                }

                if (!empty($tableData)) {
                    $io->table(['Firewall', 'Max Attempts', 'Interval', 'Storage'], $tableData);
                }
            }

            if (!empty($skippedFirewalls)) {
                $io->warning(sprintf('Skipped %d firewall(s) (already configured): %s. Use --force to update.', count($skippedFirewalls), implode(', ', $skippedFirewalls)));
            }
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to update security.yaml: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Converts seconds to a human-readable interval string for Symfony.
     *
     * @param int $seconds Number of seconds
     *
     * @return string Interval string (e.g., "10 minutes", "1 hour")
     */
    private function secondsToInterval(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%d seconds', $seconds);
        }

        if ($seconds < 3600) {
            $minutes = (int) round($seconds / 60);

            return sprintf('%d minute%s', $minutes, $minutes > 1 ? 's' : '');
        }

        $hours = (int) round($seconds / 3600);

        return sprintf('%d hour%s', $hours, $hours > 1 ? 's' : '');
    }
}

