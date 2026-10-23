<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\DependencyInjection;

use Nowo\LoginThrottleBundle\Doctrine\TablePrefixListener;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiterFactory;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
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
final class NowoLoginThrottleExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Bundle name used as the Doctrine mapping key (auto_mapping registers the entity under it).
     */
    public const BUNDLE_NAME = 'NowoLoginThrottleBundle';

    /**
     * Boolean parameter: whether the LoginAttempt entity is mapped (resolved doctrine.register_mapping).
     */
    public const MAPPING_ENABLED_PARAMETER = 'nowo_login_throttle.doctrine.mapping_enabled';

    /**
     * Base service ID of the database rate limiter registered for the single-firewall configuration.
     */
    public const DATABASE_RATE_LIMITER_SERVICE_ID = 'nowo_login_throttle.database_rate_limiter';

    /**
     * Returns the database rate limiter service ID registered for a single-firewall configuration.
     *
     * {@code main} and {@code default} use {@see self::DATABASE_RATE_LIMITER_SERVICE_ID}; any other
     * firewall gets a {@code .<firewall>} suffix. The extension and {@code nowo:login-throttle:configure-security}
     * both use this helper so security.yaml always references the service that actually exists.
     *
     * @param string $firewallName The firewall name
     *
     * @return string The service ID
     */
    public static function databaseRateLimiterServiceId(string $firewallName): string
    {
        if ('default' === $firewallName || 'main' === $firewallName) {
            return self::DATABASE_RATE_LIMITER_SERVICE_ID;
        }

        return \sprintf('%s.%s', self::DATABASE_RATE_LIMITER_SERVICE_ID, $firewallName);
    }

    /**
     * Returns the shared database rate limiter service ID for a multiple-firewalls configuration tuple.
     *
     * Format: {@code nowo_login_throttle.database_rate_limiter.shared_{max}_{timeout}s_{watch}s}, with an
     * {@code _ip{n}} suffix only when {@code max_count_attempts_per_ip} is set explicitly.
     *
     * @param int      $maxAttempts      Maximum attempts
     * @param int      $timeout          Timeout in seconds
     * @param int      $watchPeriod      Watch period in seconds
     * @param int|null $maxAttemptsPerIp Explicit per-IP maximum (null: default multiplier)
     *
     * @return string The shared service ID
     */
    public static function sharedDatabaseRateLimiterServiceId(int $maxAttempts, int $timeout, int $watchPeriod, ?int $maxAttemptsPerIp = null): string
    {
        $serviceId = \sprintf(
            '%s.shared_%d_%ds_%ds',
            self::DATABASE_RATE_LIMITER_SERVICE_ID,
            $maxAttempts,
            $timeout,
            $watchPeriod
        );

        return null === $maxAttemptsPerIp ? $serviceId : \sprintf('%s_ip%d', $serviceId, $maxAttemptsPerIp);
    }

    /**
     * Loads the services configuration and processes the bundle configuration.
     *
     * Loads the services.yaml file from the bundle's Resources/config directory
     * and processes the bundle configuration to set up login throttling.
     *
     * @param array<string, mixed> $configs   Array of configuration values
     * @param ContainerBuilder     $container The container builder object
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $processor = new Processor();
        $configuration = new Configuration();
        $config = $processor->processConfiguration($configuration, $configs);

        // Store configuration as container parameters
        $container->setParameter('nowo_login_throttle.config', $config);
        $container->setParameter('nowo_login_throttle.watch_period', (int) ($config['watch_period'] ?? 3600));

        $mappingEnabled = $this->resolveRegisterMapping($config['doctrine']['register_mapping'], $this->usesDatabaseStorage($config));
        $container->setParameter(self::MAPPING_ENABLED_PARAMETER, $mappingEnabled);

        // Check if using multiple firewalls configuration
        // Only use multiple firewalls if firewalls array is not empty
        if (isset($config['firewalls']) && \is_array($config['firewalls']) && [] !== $config['firewalls']) {
            // Prefer the first firewall's watch_period when present
            $first = reset($config['firewalls']);
            if (\is_array($first) && isset($first['watch_period'])) {
                $container->setParameter('nowo_login_throttle.watch_period', (int) $first['watch_period']);
            }
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
            $databaseLimiterServiceId = null;
            if ('database' === $config['storage'] && $config['enabled']) {
                $databaseLimiterServiceId = $this->registerDatabaseRateLimiter($container, [
                    'max_count_attempts' => $config['max_count_attempts'],
                    'timeout' => $config['timeout'],
                    'watch_period' => $config['watch_period'] ?? 3600,
                    'max_count_attempts_per_ip' => $config['max_count_attempts_per_ip'] ?? null,
                ], $config['firewall']);
            }

            // Store security config for single firewall (always set, even if disabled)
            $this->configureSecurityThrottling($container, $config, $databaseLimiterServiceId);

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

        // Load services.yaml after parameters are set (needed for parameter references)
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // REQ-ORM-002: the table prefix listener only exists when a prefix is configured.
        $tablePrefix = (string) $config['doctrine']['table_prefix'];
        $container->setParameter('nowo_login_throttle.doctrine.table_prefix', $tablePrefix);
        if ('' === $tablePrefix || !$mappingEnabled) {
            $container->removeDefinition(TablePrefixListener::class);
        }

        if (!$mappingEnabled) {
            // Unmapped entity: the services built on it would fail at runtime. Consumers take the repository as optional.
            $container->removeAlias(LoginAttemptRepositoryInterface::class);
            $container->removeDefinition(LoginAttemptRepository::class);
            $container->removeDefinition(DatabaseRateLimiterFactory::class);
        }
    }

    /**
     * Doctrine auto_mapping maps src/Entity of every bundle: when doctrine.register_mapping resolves to false,
     * prepend `mappings.NowoLoginThrottleBundle.mapping: false` on the auto-mapped entity manager (unless the host
     * already configures a NowoLoginThrottleBundle mapping).
     *
     * @param ContainerBuilder $container The container builder
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $registerMapping = true;
        $merged = [];
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            $doctrine = $config['doctrine'] ?? null;
            if (\is_array($doctrine) && \array_key_exists('register_mapping', $doctrine)) {
                $registerMapping = $doctrine['register_mapping'];
            }
            if (\array_key_exists('storage', $config)) {
                $merged['storage'] = $config['storage'];
            }
            foreach (\is_array($config['firewalls'] ?? null) ? $config['firewalls'] : [] as $name => $firewall) {
                $merged['firewalls'][$name] = array_replace($merged['firewalls'][$name] ?? [], \is_array($firewall) ? $firewall : []);
            }
        }

        $mapped = Configuration::REGISTER_MAPPING_AUTO === $registerMapping
            ? $this->usesDatabaseStorage($merged)
            : false !== $registerMapping;
        if ($mapped) {
            return;
        }

        $manager = null;
        $hasOrm = false;
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            if (!\is_array($config['orm'] ?? null)) {
                continue;
            }
            $hasOrm = true;
            $orm = $config['orm'];
            if (\is_array($orm['mappings'] ?? null) && \array_key_exists(self::BUNDLE_NAME, $orm['mappings'])) {
                return;
            }
            if (\is_string($orm['default_entity_manager'] ?? null)) {
                $manager ??= $orm['default_entity_manager'];
            }
            foreach (\is_array($orm['entity_managers'] ?? null) ? $orm['entity_managers'] : [] as $name => $entityManager) {
                if (!\is_array($entityManager)) {
                    continue;
                }
                if (\is_array($entityManager['mappings'] ?? null) && \array_key_exists(self::BUNDLE_NAME, $entityManager['mappings'])) {
                    return;
                }
                if (true === ($entityManager['auto_mapping'] ?? false)) {
                    $manager = (string) $name;
                }
            }
        }

        if (!$hasOrm) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => ['entity_managers' => [$manager ?? 'default' => ['mappings' => [self::BUNDLE_NAME => ['mapping' => false]]]]],
        ]);
    }

    /**
     * Resolves doctrine.register_mapping: true / false / "auto" (mapped only when a firewall uses storage=database).
     *
     * @param bool|string $registerMapping     Configured value
     * @param bool        $usesDatabaseStorage Whether a firewall uses storage=database
     *
     * @throws InvalidArgumentException When register_mapping is false while a firewall uses storage=database
     */
    private function resolveRegisterMapping(bool|string $registerMapping, bool $usesDatabaseStorage): bool
    {
        if (Configuration::REGISTER_MAPPING_AUTO === $registerMapping) {
            return $usesDatabaseStorage;
        }

        if (false === $registerMapping && $usesDatabaseStorage) {
            throw new InvalidArgumentException('"nowo_login_throttle.doctrine.register_mapping: false" requires storage "cache" on every firewall: storage "database" needs the LoginAttempt entity (login_attempts table). Use "auto" or true.');
        }

        return (bool) $registerMapping;
    }

    /**
     * Whether the configuration stores attempts in the database: any entry of the multiple-firewalls
     * configuration when it is not empty, otherwise the single-firewall storage.
     *
     * @param array<array-key, mixed> $config Processed configuration (or merged raw configuration in prepend())
     */
    private function usesDatabaseStorage(array $config): bool
    {
        $firewalls = \is_array($config['firewalls'] ?? null) ? $config['firewalls'] : [];
        if ([] === $firewalls) {
            return 'database' === ($config['storage'] ?? null);
        }

        foreach ($firewalls as $firewall) {
            if (\is_array($firewall) && 'database' === ($firewall['storage'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configures security throttling parameters.
     *
     * Sets up parameters that can be used to configure security.yaml.
     * The actual security.yaml configuration should be done manually or via a recipe.
     *
     * @param ContainerBuilder     $container                The container builder
     * @param array<string, mixed> $config                   The processed configuration
     * @param string|null          $databaseLimiterServiceId Service ID returned by {@see registerDatabaseRateLimiter()} (null when not registered)
     */
    private function configureSecurityThrottling(ContainerBuilder $container, array $config, ?string $databaseLimiterServiceId): void
    {
        $maxAttempts = $config['max_count_attempts'];
        $interval = Configuration::secondsToInterval($config['timeout']);
        $firewall = $config['firewall'];
        $rateLimiter = $config['rate_limiter'];
        $cachePool = $config['cache_pool'];
        $lockFactory = $config['lock_factory'];

        // Determine the rate limiter to use
        $limiterService = $rateLimiter;
        if ('database' === $config['storage'] && null === $rateLimiter) {
            // Use the database rate limiter service actually registered for this firewall
            $limiterService = $databaseLimiterServiceId ?? self::databaseRateLimiterServiceId($firewall);
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
     */
    private function processMultipleFirewalls(ContainerBuilder $container, array $firewallsConfig): void
    {
        // Set enabled parameter based on whether any firewall is enabled
        $anyEnabled = false;
        foreach ($firewallsConfig as $firewallConfig) {
            if (($firewallConfig['enabled'] ?? true) === true) {
                $anyEnabled = true;
                break;
            }
        }
        $container->setParameter('nowo_login_throttle.enabled', $anyEnabled);
        $firewallsData = [];
        $sharedLimiters = []; // Track shared rate limiters by config key

        // First pass: collect all database rate limiters that should be shared
        // Firewalls with the same configuration (max_attempts, timeout, watch_period) will share
        // the same rate limiter service to optimize resource usage and ensure consistent behavior
        foreach ($firewallsConfig as $firewallName => $firewallConfig) {
            if (!($firewallConfig['enabled'] ?? true)) {
                continue;
            }

            $limiterServiceId = $firewallConfig['rate_limiter'] ?? null;
            if ('database' === $firewallConfig['storage'] && null === $limiterServiceId) {
                $this->resolveSharedLimiterServiceId(
                    $sharedLimiters,
                    $firewallConfig['max_count_attempts'],
                    $firewallConfig['timeout'],
                    $firewallConfig['watch_period'] ?? 3600,
                    $firewallConfig['max_count_attempts_per_ip'] ?? null
                );
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
            if ('database' === $firewallConfig['storage'] && null === $limiterServiceId) {
                $maxAttempts = $firewallConfig['max_count_attempts'];
                $timeout = $firewallConfig['timeout'];
                $watchPeriod = $firewallConfig['watch_period'] ?? 3600;
                $maxAttemptsPerIp = $firewallConfig['max_count_attempts_per_ip'] ?? null;

                $sharedServiceId = $this->resolveSharedLimiterServiceId(
                    $sharedLimiters,
                    $maxAttempts,
                    $timeout,
                    $watchPeriod,
                    $maxAttemptsPerIp
                );

                // Register the limiter only once (multiple firewalls with same config share the same service)
                // This optimizes resource usage and ensures consistent behavior across firewalls
                if (!isset($registeredLimiters[$sharedServiceId])) {
                    $this->registerDatabaseRateLimiterByServiceId($container, $sharedServiceId, [
                        'max_count_attempts' => $maxAttempts,
                        'timeout' => $timeout,
                        'watch_period' => $watchPeriod,
                        'max_count_attempts_per_ip' => $maxAttemptsPerIp,
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
        // For single firewall (main/default), use default service ID. Other firewalls get a suffixed ID
        $serviceId = self::databaseRateLimiterServiceId($firewallName);

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
     * @param array<string, mixed> $config    The processed configuration (must contain max_count_attempts, timeout, watch_period; optional max_count_attempts_per_ip)
     */
    private function registerDatabaseRateLimiterByServiceId(ContainerBuilder $container, string $serviceId, array $config): void
    {
        // Check if service already exists
        if ($container->hasDefinition($serviceId)) {
            return;
        }

        // Register the database rate limiter service
        $container->register($serviceId, DatabaseRateLimiter::class)
            ->setArguments([
                new Reference(LoginAttemptRepository::class),
                $config['max_count_attempts'],
                $config['timeout'],
                $config['max_count_attempts_per_ip'] ?? null,
                new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setPublic(true); // Must be public to be used as a rate limiter service
    }

    /**
     * Resolves or creates a shared database rate limiter service ID for a configuration tuple.
     *
     * @param array<string, string> $sharedLimiters   Shared limiter registry keyed by config hash
     * @param int                   $maxAttempts      Maximum attempts
     * @param int                   $timeout          Timeout in seconds
     * @param int                   $watchPeriod      Watch period in seconds
     * @param int|null              $maxAttemptsPerIp Explicit per-IP maximum (null: default multiplier)
     *
     * @return string The shared service ID
     */
    private function resolveSharedLimiterServiceId(array &$sharedLimiters, int $maxAttempts, int $timeout, int $watchPeriod, ?int $maxAttemptsPerIp = null): string
    {
        $limiterKey = \sprintf('db-%d-%d-%d-%s', $maxAttempts, $timeout, $watchPeriod, $maxAttemptsPerIp ?? 'default');

        if (!isset($sharedLimiters[$limiterKey])) {
            $sharedLimiters[$limiterKey] = self::sharedDatabaseRateLimiterServiceId($maxAttempts, $timeout, $watchPeriod, $maxAttemptsPerIp);
        }

        return $sharedLimiters[$limiterKey];
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
