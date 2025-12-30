<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\DependencyInjection;

use RuntimeException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration definition for Login Throttle Bundle.
 *
 * This class defines the structure and default values for the bundle configuration.
 * Users can override these defaults in their config/packages/nowo_login_throttle.yaml file.
 *
 * The configuration is compatible with the deprecated anyx/login-gate-bundle options:
 * - max_count_attempts: Maps to max_attempts in Symfony login_throttling
 * - timeout: Maps to interval in Symfony login_throttling (converted from seconds)
 * - watch_period: Period for tracking attempts (for informational purposes)
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
class Configuration implements ConfigurationInterface
{
    /**
     * The extension alias.
     */
    public const ALIAS = 'nowo_login_throttle';

    /**
     * Builds the configuration tree.
     *
     * Defines the structure of the bundle configuration with all available options
     * and their default values. These defaults match the common settings from
     * anyx/login-gate-bundle for easy migration.
     *
     * @return TreeBuilder The configuration tree builder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                // Simple configuration (single firewall) - for backward compatibility
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Enable or disable login throttling (for simple single-firewall configuration)')
                ->end()
                ->integerNode('max_count_attempts')
                    ->defaultValue(3)
                    ->min(1)
                    ->info('Maximum number of login attempts before throttling (for simple single-firewall configuration)')
                ->end()
                ->integerNode('timeout')
                    ->defaultValue(600)
                    ->min(1)
                    ->info('Ban period in seconds (for simple single-firewall configuration)')
                ->end()
                ->integerNode('watch_period')
                    ->defaultValue(3600)
                    ->min(1)
                    ->info('Period in seconds for tracking attempts (for simple single-firewall configuration)')
                ->end()
                ->scalarNode('firewall')
                    ->defaultValue('main')
                    ->info('Firewall name where login_throttling should be applied (for simple single-firewall configuration)')
                    ->cannotBeEmpty()
                ->end()
                ->enumNode('storage')
                    ->values(['cache', 'database'])
                    ->defaultValue('cache')
                    ->info('Storage backend for login attempts (for simple single-firewall configuration)')
                ->end()
                ->scalarNode('rate_limiter')
                    ->defaultNull()
                    ->info('Custom rate limiter service ID (for simple single-firewall configuration)')
                    ->example('login_throttle_limiter')
                ->end()
                ->scalarNode('cache_pool')
                    ->defaultValue('cache.rate_limiter')
                    ->info('Cache pool to use for storing the limiter state (for simple single-firewall configuration, only used when storage=cache)')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('lock_factory')
                    ->defaultNull()
                    ->info('Lock factory service ID for rate limiter (for simple single-firewall configuration, only used when storage=cache)')
                ->end()
                // Multiple firewalls configuration
                ->arrayNode('firewalls')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')
                                ->defaultTrue()
                                ->info('Enable or disable login throttling for this firewall')
                            ->end()
                            ->integerNode('max_count_attempts')
                                ->defaultValue(3)
                                ->min(1)
                                ->info('Maximum number of login attempts before throttling')
                            ->end()
                            ->integerNode('timeout')
                                ->defaultValue(600)
                                ->min(1)
                                ->info('Ban period in seconds')
                            ->end()
                            ->integerNode('watch_period')
                                ->defaultValue(3600)
                                ->min(1)
                                ->info('Period in seconds for tracking attempts')
                            ->end()
                            ->enumNode('storage')
                                ->values(['cache', 'database'])
                                ->defaultValue('cache')
                                ->info('Storage backend for login attempts')
                            ->end()
                            ->scalarNode('rate_limiter')
                                ->defaultNull()
                                ->info('Custom rate limiter service ID (optional). If not provided, Symfony will use default login throttling rate limiter or database rate limiter if storage=database. Use same service ID to share rate limiter across firewalls.')
                                ->example('login_throttle_limiter')
                            ->end()
                            ->scalarNode('cache_pool')
                                ->defaultValue('cache.rate_limiter')
                                ->info('Cache pool to use for storing the limiter state (only used when storage=cache)')
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('lock_factory')
                                ->defaultNull()
                                ->info('Lock factory service ID for rate limiter (optional, only used when storage=cache)')
                            ->end()
                        ->end()
                    ->end()
                    ->info('Configure multiple firewalls with independent throttling settings. Each firewall can have its own configuration or share a rate limiter.')
                    ->example([
                        'main' => [
                            'max_count_attempts' => 3,
                            'timeout' => 600,
                            'storage' => 'cache',
                        ],
                        'api' => [
                            'max_count_attempts' => 5,
                            'timeout' => 300,
                            'storage' => 'database',
                        ],
                    ])
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * Generates a default configuration YAML file at the given path.
     *
     * @param string $configPath Absolute path to the configuration file to generate
     *
     * @throws RuntimeException If the symfony/yaml component is not installed
     */
    public function generateConfigFile(string $configPath): void
    {
        if (!class_exists(Yaml::class)) {
            throw new RuntimeException('Missing symfony/yaml component. Install it with: composer require symfony/yaml');
        }

        $config = [
            self::ALIAS => [
                'enabled' => true,
                'max_count_attempts' => 3,
                'timeout' => 600,
                'watch_period' => 3600,
                'firewall' => 'main',
                'storage' => 'cache',
                'rate_limiter' => null,
                'cache_pool' => 'cache.rate_limiter',
                'lock_factory' => null,
            ],
        ];

        $yaml = Yaml::dump($config, 4, 2);

        $dir = \dirname($configPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents($configPath, $yaml);
    }

    /**
     * Converts seconds to a human-readable interval string for Symfony.
     *
     * @param int $seconds Number of seconds
     *
     * @return string Interval string (e.g., "10 minutes", "1 hour")
     */
    public static function secondsToInterval(int $seconds): string
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

