<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\DependencyInjection;

use Nowo\LoginThrottleBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
        $this->expectException(InvalidConfigurationException::class);

        $configs = [
            [
                'firewall' => '',
            ],
        ];

        $this->processor->processConfiguration($this->configuration, $configs);
    }

    public function testConfigurationValidationRejectsInvalidMaxAttempts(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $configs = [
            [
                'max_count_attempts' => 0,
            ],
        ];

        $this->processor->processConfiguration($this->configuration, $configs);
    }

    public function testConfigurationValidationRejectsInvalidTimeout(): void
    {
        $this->expectException(InvalidConfigurationException::class);

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
        $this->expectException(InvalidConfigurationException::class);

        $configs = [
            [
                'watch_period' => 0,
            ],
        ];

        $this->processor->processConfiguration($this->configuration, $configs);
    }

    public function testWithoutUsernameBoundDefaultsToNull(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [['firewalls' => ['api' => ['storage' => 'database']]]]);

        $this->assertNull($config['max_count_attempts_without_username']);
        $this->assertNull($config['firewalls']['api']['max_count_attempts_without_username']);
    }

    public function testWithoutUsernameBoundUpToTheEffectivePerIpCapIsAccepted(): void
    {
        // default per-IP cap: 5 × max_count_attempts = 15
        $config = $this->processor->processConfiguration($this->configuration, [[
            'max_count_attempts_without_username' => 15,
            'firewalls' => [
                'main' => ['max_count_attempts' => 4, 'max_count_attempts_per_ip' => 40, 'max_count_attempts_without_username' => 5],
                'api' => ['max_count_attempts_without_username' => 15],
            ],
        ]]);

        $this->assertSame(15, $config['max_count_attempts_without_username']);
        $this->assertSame(5, $config['firewalls']['main']['max_count_attempts_without_username']);
        $this->assertSame(15, $config['firewalls']['api']['max_count_attempts_without_username']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidWithoutUsernameBounds(): iterable
    {
        yield 'zero' => [['max_count_attempts_without_username' => 0], 'max_count_attempts_without_username'];
        yield 'above the default cap' => [['max_count_attempts' => 3, 'max_count_attempts_without_username' => 16], 'cannot exceed the per-IP cap (15)'];
        yield 'above the explicit cap' => [['max_count_attempts_per_ip' => 10, 'max_count_attempts_without_username' => 11], 'cannot exceed the per-IP cap (10)'];
        yield 'firewall above its cap' => [['firewalls' => ['api' => ['max_count_attempts' => 2, 'max_count_attempts_without_username' => 11]]], 'cannot exceed the per-IP cap (10)'];
        yield 'firewall zero' => [['firewalls' => ['api' => ['max_count_attempts_without_username' => 0]]], 'max_count_attempts_without_username'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidWithoutUsernameBounds')]
    public function testWithoutUsernameBoundIsValidated(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->processor->processConfiguration($this->configuration, [$config]);
    }
}
