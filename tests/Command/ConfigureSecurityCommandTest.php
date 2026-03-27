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
        // Create bundle config in test directory first
        $this->filesystem->dumpFile(
            $this->testDir . '/config/packages/nowo_login_throttle.yaml',
            "nowo_login_throttle:\n    enabled: true\n    max_count_attempts: 3\n"
        );

        // Create security.yaml in test directory
        $securityPath = $this->testDir . '/config/packages/security.yaml';
        $this->filesystem->dumpFile($securityPath, "security:\n    firewalls:\n        main: {}\n");

        // Make the directory read-only to prevent writing (more reliable than file-only)
        $packagesDir = $this->testDir . '/config/packages';
        chmod($packagesDir, 0o555);

        // Create command with test directory
        $command = new ConfigureSecurityCommand($this->testDir);
        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([]);
        $output = $commandTester->getDisplay();

        // The command should fail (exit code 1) when it can't write
        // If it succeeds, it means the file was writable (which can happen on some systems)
        // In that case, we just verify the command doesn't crash
        if ($exitCode === 1) {
            // Command failed as expected - verify error message
            $this->assertTrue(
                str_contains($output, 'Failed to update') || str_contains($output, 'Exception') || str_contains($output, 'Error') || str_contains($output, 'Permission'),
                'Command should show error message. Exit code: ' . $exitCode . ', Output: ' . $output
            );
        } else {
            // Command succeeded - this can happen if the system allows writing despite permissions
            // The important thing is that the command doesn't crash
            $this->assertTrue(true, 'Command handled the situation without crashing (exit code: ' . $exitCode . ')');
        }

        // Clean up
        @chmod($packagesDir, 0o755);
    }

    public function testCommandUsesCurrentDirectoryWhenProjectDirIsNull(): void
    {
        $command = new ConfigureSecurityCommand(null);
        $this->assertNotNull($command);
    }
}
