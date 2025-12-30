<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\DependencyInjection;

use Nowo\LoginThrottleBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for Configuration::generateConfigFile method.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class ConfigurationGenerateConfigFileTest extends TestCase
{
    private string $testDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/config_test_' . uniqid();
        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->testDir)) {
            $this->filesystem->remove($this->testDir);
        }
    }

    public function testGenerateConfigFileCreatesFile(): void
    {
        $configPath = $this->testDir . '/config.yaml';
        $configuration = new Configuration();

        $configuration->generateConfigFile($configPath);

        $this->assertFileExists($configPath);
    }

    public function testGenerateConfigFileCreatesDirectory(): void
    {
        $configPath = $this->testDir . '/subdir/config.yaml';
        $configuration = new Configuration();

        $configuration->generateConfigFile($configPath);

        $this->assertFileExists($configPath);
        $this->assertDirectoryExists($this->testDir . '/subdir');
    }

    public function testGenerateConfigFileContent(): void
    {
        $configPath = $this->testDir . '/config.yaml';
        $configuration = new Configuration();

        $configuration->generateConfigFile($configPath);

        $config = Yaml::parseFile($configPath);
        $this->assertArrayHasKey('nowo_login_throttle', $config);
        $this->assertTrue($config['nowo_login_throttle']['enabled']);
        $this->assertSame(3, $config['nowo_login_throttle']['max_count_attempts']);
        $this->assertSame(600, $config['nowo_login_throttle']['timeout']);
        $this->assertSame(3600, $config['nowo_login_throttle']['watch_period']);
        $this->assertSame('main', $config['nowo_login_throttle']['firewall']);
        $this->assertNull($config['nowo_login_throttle']['rate_limiter']);
        $this->assertSame('cache.rate_limiter', $config['nowo_login_throttle']['cache_pool']);
        $this->assertNull($config['nowo_login_throttle']['lock_factory']);
    }

}

