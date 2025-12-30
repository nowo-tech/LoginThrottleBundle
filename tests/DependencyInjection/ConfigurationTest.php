<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\DependencyInjection;

use Nowo\LoginThrottleBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Tests for Configuration class.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class ConfigurationTest extends TestCase
{
    private Configuration $configuration;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->configuration = new Configuration();
        $this->processor = new Processor();
    }

    public function testGetConfigTreeBuilder(): void
    {
        $treeBuilder = $this->configuration->getConfigTreeBuilder();
        $this->assertNotNull($treeBuilder);
    }

    public function testDefaultConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, []);

        $this->assertTrue($config['enabled']);
        $this->assertSame(3, $config['max_count_attempts']);
        $this->assertSame(600, $config['timeout']);
        $this->assertSame(3600, $config['watch_period']);
        $this->assertSame('main', $config['firewall']);
        $this->assertNull($config['rate_limiter']);
        $this->assertSame('cache.rate_limiter', $config['cache_pool']);
        $this->assertNull($config['lock_factory']);
    }

    public function testCustomConfiguration(): void
    {
        $configs = [
            [
                'enabled' => false,
                'max_count_attempts' => 5,
                'timeout' => 900,
                'watch_period' => 7200,
                'firewall' => 'api',
                'rate_limiter' => 'custom_limiter',
                'cache_pool' => 'cache.custom',
                'lock_factory' => 'lock.factory',
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, $configs);

        $this->assertFalse($config['enabled']);
        $this->assertSame(5, $config['max_count_attempts']);
        $this->assertSame(900, $config['timeout']);
        $this->assertSame(7200, $config['watch_period']);
        $this->assertSame('api', $config['firewall']);
        $this->assertSame('custom_limiter', $config['rate_limiter']);
        $this->assertSame('cache.custom', $config['cache_pool']);
        $this->assertSame('lock.factory', $config['lock_factory']);
    }

    public function testConfigurationValidationRejectsEmptyFirewall(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $configs = [
            [
                'firewall' => '',
            ],
        ];

        $this->processor->processConfiguration($this->configuration, $configs);
    }

    public function testConfigurationValidationRejectsInvalidMaxAttempts(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $configs = [
            [
                'max_count_attempts' => 0,
            ],
        ];

        $this->processor->processConfiguration($this->configuration, $configs);
    }

    public function testConfigurationValidationRejectsInvalidTimeout(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $configs = [
            [
                'timeout' => 0,
            ],
        ];

        $this->processor->processConfiguration($this->configuration, $configs);
    }

    public function testSecondsToInterval(): void
    {
        $this->assertSame('30 seconds', Configuration::secondsToInterval(30));
        $this->assertSame('1 minute', Configuration::secondsToInterval(60));
        $this->assertSame('5 minutes', Configuration::secondsToInterval(300));
        $this->assertSame('1 hour', Configuration::secondsToInterval(3600));
        $this->assertSame('2 hours', Configuration::secondsToInterval(7200));
    }

    public function testConfigurationValidationRejectsInvalidWatchPeriod(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $configs = [
            [
                'watch_period' => 0,
            ],
        ];

        $this->processor->processConfiguration($this->configuration, $configs);
    }
}
