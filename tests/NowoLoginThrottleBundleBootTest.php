<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests;

use Nowo\LoginThrottleBundle\NowoLoginThrottleBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests for NowoLoginThrottleBundle boot method.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class NowoLoginThrottleBundleBootTest extends TestCase
{
    private string $testDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/login_throttle_boot_test_' . uniqid();
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->testDir);
        $this->filesystem->mkdir($this->testDir . '/config');
        $this->filesystem->mkdir($this->testDir . '/config/packages');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->testDir);
    }

    public function testBootGeneratesConfigFileWhenNotExists(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('hasParameter')->with('kernel.project_dir')->willReturn(true);
        $container->method('getParameter')->with('kernel.project_dir')->willReturn($this->testDir);

        $reflection = new \ReflectionClass($bundle);
        $property = $reflection->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($bundle, $container);

        $bundle->boot();

        $configPath = $this->testDir . '/config/packages/nowo_login_throttle.yaml';
        $this->assertFileExists($configPath);

        $config = \Symfony\Component\Yaml\Yaml::parseFile($configPath);
        $this->assertArrayHasKey('nowo_login_throttle', $config);
        $this->assertTrue($config['nowo_login_throttle']['enabled']);
        $this->assertSame(3, $config['nowo_login_throttle']['max_count_attempts']);
    }

    public function testBootSkipsWhenConfigAlreadyExists(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('hasParameter')->with('kernel.project_dir')->willReturn(true);
        $container->method('getParameter')->with('kernel.project_dir')->willReturn($this->testDir);

        // Create existing config file
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 5\n"
        );

        $reflection = new \ReflectionClass($bundle);
        $property = $reflection->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($bundle, $container);

        $bundle->boot();

        // Verify config was not overwritten
        $config = \Symfony\Component\Yaml\Yaml::parseFile($this->testDir . '/config/packages/nowo_login_throttle.yaml');
        $this->assertSame(5, $config['nowo_login_throttle']['max_count_attempts']);
    }

    public function testBootSkipsWhenConfigDefinedInOtherFile(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('hasParameter')->with('kernel.project_dir')->willReturn(true);
        $container->method('getParameter')->with('kernel.project_dir')->willReturn($this->testDir);

        // Create config in another file
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/custom.yaml',
            "nowo_login_throttle:\n    enabled: true\n"
        );

        $reflection = new \ReflectionClass($bundle);
        $property = $reflection->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($bundle, $container);

        $bundle->boot();

        // Verify config file was not created
        $configPath = $this->testDir . '/config/packages/nowo_login_throttle.yaml';
        $this->assertFileDoesNotExist($configPath);
    }

    public function testBootSkipsWhenNoProjectDir(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('hasParameter')->with('kernel.project_dir')->willReturn(false);

        $reflection = new \ReflectionClass($bundle);
        $property = $reflection->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($bundle, $container);

        $bundle->boot();

        // Verify config file was not created
        $configPath = $this->testDir . '/config/packages/nowo_login_throttle.yaml';
        $this->assertFileDoesNotExist($configPath);
    }

    public function testIsConfigurationDefinedReturnsTrueWhenConfigExists(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $reflection = new \ReflectionClass($bundle);
        $method = $reflection->getMethod('isConfigurationDefined');
        $method->setAccessible(true);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/test.yaml',
            "nowo_login_throttle:\n    enabled: true\n"
        );

        $result = $method->invoke($bundle, $this->testDir . '/config/packages');
        $this->assertTrue($result);
    }

    public function testIsConfigurationDefinedReturnsFalseWhenConfigNotExists(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $reflection = new \ReflectionClass($bundle);
        $method = $reflection->getMethod('isConfigurationDefined');
        $method->setAccessible(true);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/test.yaml',
            "other_bundle:\n    enabled: true\n"
        );

        $result = $method->invoke($bundle, $this->testDir . '/config/packages');
        $this->assertFalse($result);
    }

    public function testIsConfigurationDefinedReturnsFalseWhenDirectoryNotExists(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $reflection = new \ReflectionClass($bundle);
        $method = $reflection->getMethod('isConfigurationDefined');
        $method->setAccessible(true);

        $result = $method->invoke($bundle, '/non/existent/directory');
        $this->assertFalse($result);
    }

    public function testIsConfigurationDefinedChecksYmlFiles(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $reflection = new \ReflectionClass($bundle);
        $method = $reflection->getMethod('isConfigurationDefined');
        $method->setAccessible(true);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/test.yml',
            "nowo_login_throttle:\n    enabled: true\n"
        );

        $result = $method->invoke($bundle, $this->testDir . '/config/packages');
        $this->assertTrue($result);
    }

    public function testBootCreatesConfigDirectoryIfNotExists(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('hasParameter')->with('kernel.project_dir')->willReturn(true);
        $container->method('getParameter')->with('kernel.project_dir')->willReturn($this->testDir);

        // Remove config/packages directory
        $this->filesystem->remove($this->testDir . '/config/packages');

        $reflection = new \ReflectionClass($bundle);
        $property = $reflection->getProperty('container');
        $property->setAccessible(true);
        $property->setValue($bundle, $container);

        $bundle->boot();

        $configPath = $this->testDir . '/config/packages/nowo_login_throttle.yaml';
        $this->assertFileExists($configPath);
        $this->assertDirectoryExists($this->testDir . '/config/packages');
    }
}
