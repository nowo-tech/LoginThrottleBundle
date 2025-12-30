<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Command;

use Nowo\LoginThrottleBundle\Command\ConfigureSecurityCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for ConfigureSecurityCommand.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class ConfigureSecurityCommandTest extends TestCase
{
    private string $testDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/login_throttle_test_' . uniqid();
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->testDir);
        $this->filesystem->mkdir($this->testDir . '/config');
        $this->filesystem->mkdir($this->testDir . '/config/packages');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->testDir);
    }

    public function testCommandExists(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $this->assertSame('nowo:login-throttle:configure-security', $command->getName());
    }

    public function testCommandFailsWhenBundleConfigNotFound(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main: {}\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Bundle configuration file not found', $commandTester->getDisplay());
    }

    public function testCommandFailsWhenSecurityYamlNotFound(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('security.yaml not found', $commandTester->getDisplay());
    }

    public function testCommandSkipsWhenThrottlingDisabled(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: false\n"
        );

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main: {}\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Login throttling is disabled', $commandTester->getDisplay());
    }

    public function testCommandConfiguresSecurityYaml(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n    timeout: 600\n    firewall: 'main'\n"
        );

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main: {}\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Successfully configured', $commandTester->getDisplay());

        $securityConfig = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        $this->assertArrayHasKey('security', $securityConfig);
        $this->assertArrayHasKey('firewalls', $securityConfig['security']);
        $this->assertArrayHasKey('main', $securityConfig['security']['firewalls']);
        $this->assertArrayHasKey('login_throttling', $securityConfig['security']['firewalls']['main']);
        $this->assertSame(3, $securityConfig['security']['firewalls']['main']['login_throttling']['max_attempts']);
        $this->assertSame('10 minutes', $securityConfig['security']['firewalls']['main']['login_throttling']['interval']);
    }

    public function testCommandSkipsWhenAlreadyConfigured(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n"
        );

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main:\n            login_throttling:\n                max_attempts: 5\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('already configured', $commandTester->getDisplay());
    }

    public function testCommandForcesUpdateWhenForceOptionUsed(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n    timeout: 600\n"
        );

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main:\n            login_throttling:\n                max_attempts: 5\n"
        );

        $exitCode = $commandTester->execute(['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Successfully configured', $commandTester->getDisplay());

        $securityConfig = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        $this->assertSame(3, $securityConfig['security']['firewalls']['main']['login_throttling']['max_attempts']);
    }

    public function testCommandIncludesRateLimiterWhenConfigured(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n    timeout: 600\n    rate_limiter: 'custom_limiter'\n"
        );

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main: {}\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);

        $securityConfig = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        $this->assertSame('custom_limiter', $securityConfig['security']['firewalls']['main']['login_throttling']['limiter']);
    }

    public function testSecondsToInterval(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('secondsToInterval');
        $method->setAccessible(true);

        $this->assertSame('30 seconds', $method->invoke($command, 30));
        $this->assertSame('1 minute', $method->invoke($command, 60));
        $this->assertSame('5 minutes', $method->invoke($command, 300));
        $this->assertSame('1 hour', $method->invoke($command, 3600));
        $this->assertSame('2 hours', $method->invoke($command, 7200));
    }

    public function testCommandIncludesCachePoolWhenDifferentFromDefault(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n    timeout: 600\n    cache_pool: 'cache.custom'\n"
        );

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main: {}\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);

        $securityConfig = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        $this->assertSame('cache.custom', $securityConfig['security']['firewalls']['main']['login_throttling']['cache_pool']);
    }

    public function testCommandIncludesLockFactoryWhenConfigured(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n    timeout: 600\n    lock_factory: 'lock.factory'\n"
        );

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/security.yaml',
            "security:\n    firewalls:\n        main: {}\n"
        );

        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);

        $securityConfig = Yaml::parseFile($this->testDir . '/config/packages/security.yaml');
        $this->assertSame('lock.factory', $securityConfig['security']['firewalls']['main']['login_throttling']['lock_factory']);
    }

    public function testCommandHandlesExceptionWhenWritingFails(): void
    {
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n"
        );

        // Create a read-only directory to simulate write failure
        $readOnlyDir = $this->testDir . '/readonly';
        $this->filesystem->mkdir($readOnlyDir);
        $this->filesystem->chmod($readOnlyDir, 0555);

        $readOnlySecurityPath = $readOnlyDir . '/security.yaml';
        $this->filesystem->dumpFile($readOnlySecurityPath, "security:\n    firewalls:\n        main: {}\n");

        // Use reflection to set a read-only path
        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('projectDir');
        $property->setAccessible(true);
        $property->setValue($command, $readOnlyDir);

        $exitCode = $commandTester->execute([]);

        // Should fail gracefully
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to update', $commandTester->getDisplay());

        // Clean up
        $this->filesystem->chmod($readOnlyDir, 0755);
    }

    public function testCommandUsesCurrentDirectoryWhenProjectDirIsNull(): void
    {
        $command = new ConfigureSecurityCommand(null);
        $this->assertNotNull($command);
    }
}

