<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\DependencyInjection;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Extension class that loads and manages the LoginThrottle bundle configuration.
 *
 * This extension processes the bundle configuration and automatically configures
 * Symfony's security.yaml with login_throttling settings based on the bundle config.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
class NowoLoginThrottleExtension extends Extension
{
    /**
     * Loads the services configuration and processes the bundle configuration.
     *
     * Loads the services.yaml file from the bundle's Resources/config directory
     * and processes the bundle configuration to set up login throttling.
     *
     * @param array<string, mixed> $configs   Array of configuration values
     * @param ContainerBuilder     $container The container builder object
     *
     * @return void
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $processor = new Processor();
        $configuration = new Configuration();
        $config = $processor->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Store configuration as container parameters
        $container->setParameter('nowo_login_throttle.config', $config);

        // Check if using multiple firewalls configuration
        if (!empty($config['firewalls']) && is_array($config['firewalls'])) {
            // Multiple firewalls configuration
            $this->processMultipleFirewalls($container, $config['firewalls']);
        } else {
            // Single firewall configuration (backward compatibility)
            $container->setParameter('nowo_login_throttle.enabled', $config['enabled']);
            $container->setParameter('nowo_login_throttle.max_attempts', $config['max_count_attempts']);
            $container->setParameter('nowo_login_throttle.interval', Configuration::secondsToInterval($config['timeout']));
            $container->setParameter('nowo_login_throttle.firewall', $config['firewall']);
            $container->setParameter('nowo_login_throttle.storage', $config['storage']);
            $container->setParameter('nowo_login_throttle.rate_limiter', $config['rate_limiter']);
            $container->setParameter('nowo_login_throttle.cache_pool', $config['cache_pool']);
            $container->setParameter('nowo_login_throttle.lock_factory', $config['lock_factory']);

            // Register database rate limiter service if storage is database
            if ($config['storage'] === 'database' && $config['enabled']) {
                $this->registerDatabaseRateLimiter($container, [
                    'max_count_attempts' => $config['max_count_attempts'],
                    'timeout' => $config['timeout'],
                    'watch_period' => $config['watch_period'] ?? 3600,
                ], $config['firewall']);
            }

            // Store security config for single firewall
            if ($config['enabled']) {
                $this->configureSecurityThrottling($container, $config);
            }

            // Store firewalls config for LoginThrottleInfoService (single firewall mode)
            $container->setParameter('nowo_login_throttle.firewalls', [
                $config['firewall'] => [
                    'max_attempts' => $config['max_count_attempts'],
                    'interval' => Configuration::secondsToInterval($config['timeout']),
                    'timeout' => $config['timeout'], // Store timeout in seconds for easier access
                    'storage' => $config['storage'],
                    'firewall' => $config['firewall'],
                ],
            ]);
        }
    }

    /**
     * Configures security throttling parameters.
     *
     * Sets up parameters that can be used to configure security.yaml.
     * The actual security.yaml configuration should be done manually or via a recipe.
     *
     * @param ContainerBuilder     $container The container builder
     * @param array<string, mixed> $config    The processed configuration
     *
     * @return void
     */
    private function configureSecurityThrottling(ContainerBuilder $container, array $config): void
    {
        $maxAttempts = $config['max_count_attempts'];
        $interval = Configuration::secondsToInterval($config['timeout']);
        $firewall = $config['firewall'];
        $rateLimiter = $config['rate_limiter'];
        $cachePool = $config['cache_pool'];
        $lockFactory = $config['lock_factory'];

        // Determine the rate limiter to use
        $limiterService = $rateLimiter;
        if ($config['storage'] === 'database' && null === $rateLimiter) {
            // Use the database rate limiter service
            $limiterService = 'nowo_login_throttle.database_rate_limiter';
        }

        // Store these for potential use in compiler passes or documentation
        $container->setParameter('nowo_login_throttle.security_config', [
            'max_attempts' => $maxAttempts,
            'interval' => $interval,
            'firewall' => $firewall,
            'limiter' => $limiterService,
            'cache_pool' => $cachePool,
            'lock_factory' => $lockFactory,
            'storage' => $config['storage'],
        ]);
    }

    /**
     * Processes multiple firewalls configuration.
     *
     * @param ContainerBuilder                    $container       The container builder
     * @param array<string, array<string, mixed>> $firewallsConfig Firewalls configuration
     *
     * @return void
     */
    private function processMultipleFirewalls(ContainerBuilder $container, array $firewallsConfig): void
    {
        $firewallsData = [];
        $sharedLimiters = []; // Track shared rate limiters by config key

        // First pass: collect all database rate limiters that should be shared
        foreach ($firewallsConfig as $firewallName => $firewallConfig) {
            if (!($firewallConfig['enabled'] ?? true)) {
                continue;
            }

            $limiterServiceId = $firewallConfig['rate_limiter'] ?? null;
            if ($firewallConfig['storage'] === 'database' && null === $limiterServiceId) {
                // Create a key based on configuration to share limiters with same config
                $limiterKey = sprintf('db-%d-%d-%d', $firewallConfig['max_count_attempts'], $firewallConfig['timeout'], $firewallConfig['watch_period'] ?? 3600);

                if (!isset($sharedLimiters[$limiterKey])) {
                    // Create a shared service ID based on config (not firewall name)
                    $sharedServiceId = sprintf('nowo_login_throttle.database_rate_limiter.shared_%s', md5($limiterKey));
                    $sharedLimiters[$limiterKey] = $sharedServiceId;
                }
            }
        }

        // Second pass: register all unique rate limiters and assign them to firewalls
        $registeredLimiters = [];
        foreach ($firewallsConfig as $firewallName => $firewallConfig) {
            if (!($firewallConfig['enabled'] ?? true)) {
                continue;
            }

            // Register database rate limiter if needed
            $limiterServiceId = $firewallConfig['rate_limiter'] ?? null;
            if ($firewallConfig['storage'] === 'database' && null === $limiterServiceId) {
                $limiterKey = sprintf('db-%d-%d-%d', $firewallConfig['max_count_attempts'], $firewallConfig['timeout'], $firewallConfig['watch_period'] ?? 3600);
                $sharedServiceId = $sharedLimiters[$limiterKey];

                // Register the limiter only once
                if (!isset($registeredLimiters[$sharedServiceId])) {
                    $this->registerDatabaseRateLimiterByServiceId($container, $sharedServiceId, [
                        'max_count_attempts' => $firewallConfig['max_count_attempts'],
                        'timeout' => $firewallConfig['timeout'],
                        'watch_period' => $firewallConfig['watch_period'] ?? 3600,
                    ]);
                    $registeredLimiters[$sharedServiceId] = true;
                }

                $limiterServiceId = $sharedServiceId;
            }

            $firewallsData[$firewallName] = [
                'max_attempts' => $firewallConfig['max_count_attempts'],
                'interval' => Configuration::secondsToInterval($firewallConfig['timeout']),
                'timeout' => $firewallConfig['timeout'], // Store timeout in seconds for easier access
                'limiter' => $limiterServiceId,
                'cache_pool' => $firewallConfig['cache_pool'] ?? 'cache.rate_limiter',
                'lock_factory' => $firewallConfig['lock_factory'] ?? null,
                'storage' => $firewallConfig['storage'],
            ];
        }

        // Store all firewalls configuration
        $container->setParameter('nowo_login_throttle.firewalls', $firewallsData);
    }

    /**
     * Registers the database rate limiter service.
     *
     * @param ContainerBuilder     $container    The container builder
     * @param array<string, mixed> $config       The processed configuration
     * @param string               $firewallName The firewall name (used for service ID when multiple firewalls)
     *
     * @return string The service ID of the registered rate limiter
     */
    private function registerDatabaseRateLimiter(ContainerBuilder $container, array $config, string $firewallName = 'default'): string
    {
        // For single firewall (main/default), use default service ID. For multiple firewalls, use unique IDs
        $serviceId = 'nowo_login_throttle.database_rate_limiter';
        if ($firewallName !== 'default' && $firewallName !== 'main') {
            $serviceId = sprintf('nowo_login_throttle.database_rate_limiter.%s', $firewallName);
        }

        // Check if service already exists (for shared limiters)
        if ($container->hasDefinition($serviceId)) {
            return $serviceId;
        }

        $this->registerDatabaseRateLimiterByServiceId($container, $serviceId, $config);

        return $serviceId;
    }

    /**
     * Registers a database rate limiter service with a specific service ID.
     *
     * @param ContainerBuilder     $container The container builder
     * @param string               $serviceId The service ID to use
     * @param array<string, mixed> $config    The processed configuration (must contain max_count_attempts, timeout, watch_period)
     *
     * @return void
     */
    private function registerDatabaseRateLimiterByServiceId(ContainerBuilder $container, string $serviceId, array $config): void
    {
        // Check if service already exists
        if ($container->hasDefinition($serviceId)) {
            return;
        }

        // Register the database rate limiter service
        $container->register($serviceId, \Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter::class)
            ->setArguments([
                new Reference(LoginAttemptRepository::class),
                $config['max_count_attempts'],
                $config['timeout'],
                $config['watch_period'] ?? 3600,
            ])
            ->setPublic(true); // Must be public to be used as a rate limiter service
    }

    /**
     * Returns the alias for this extension.
     *
     * @return string The extension alias
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
