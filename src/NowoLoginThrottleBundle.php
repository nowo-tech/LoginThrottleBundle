<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle;

use Nowo\LoginThrottleBundle\DependencyInjection\Configuration;
use Nowo\LoginThrottleBundle\DependencyInjection\NowoLoginThrottleExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for login throttling using native Symfony login_throttling feature.
 *
 * This bundle provides a simple way to configure login throttling (rate limiting)
 * for Symfony applications using the native login_throttling feature introduced
 * in Symfony 5.2. It replaces deprecated bundles like anyx/login-gate-bundle.
 *
 * Features:
 * - Native Symfony login_throttling integration
 * - Pre-configured settings with sensible defaults
 * - Default `nowo_login_throttle.yaml` generated on boot when missing (dev environment + CLI only)
 * - Console command to sync `login_throttling` into `security.yaml` (`nowo:login-throttle:configure-security`)
 * - Compatible with Symfony 7.4 and 8.x
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class NowoLoginThrottleBundle extends Bundle
{
    /**
     * @param string $sapi PHP SAPI name; only meant to be overridden in tests (defaults to PHP_SAPI)
     */
    public function __construct(
        private readonly string $sapi = \PHP_SAPI,
    ) {
    }

    /**
     * Overridden to allow for the custom extension alias.
     *
     * Creates and returns the container extension instance if not already created.
     * The extension is cached after the first call to ensure the same instance is returned
     * on subsequent calls.
     *
     * @return ExtensionInterface The container extension instance
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Symfony bundle extension lazy-init at boot; not request state
            $this->extension = new NowoLoginThrottleExtension();
        }

        return $this->extension;
    }

    /**
     * Generates the configuration file if it doesn't exist.
     *
     * This method is called during the kernel boot process. To keep production and
     * HTTP/worker boots free of filesystem work, the scan of config/packages and the
     * optional write only happen in the "dev" environment when running from the CLI
     * (e.g. `cache:clear` / Composer auto-scripts after install). In any other case
     * boot() does not touch the filesystem.
     */
    public function boot(): void
    {
        parent::boot();

        if (!isset($this->container)) {
            return;
        }

        if (!$this->isCli()) {
            return;
        }

        if (!$this->container->hasParameter('kernel.environment')
            || 'dev' !== $this->container->getParameter('kernel.environment')) {
            return;
        }

        if (!$this->container->hasParameter('kernel.project_dir')) {
            return;
        }

        $projectDir = $this->container->getParameter('kernel.project_dir');
        if (!\is_string($projectDir) || '' === $projectDir) {
            return;
        }

        $aliasBundle = Configuration::ALIAS;
        $configPath = $projectDir . \sprintf('/config/packages/%s.yaml', $aliasBundle);
        $configDir = $projectDir . '/config/packages';

        // Check if the configuration already exists in any file
        if ($this->isConfigurationDefined($configDir)) {
            return;
        }

        // Read-only images (e.g. production containers) must not fail kernel boot on the write
        if (!is_writable(is_dir($configDir) ? $configDir : $projectDir)) {
            return;
        }

        // If it doesn't exist, create the configuration file
        if (!file_exists($configPath)) {
            $configuration = new Configuration();
            $configuration->generateConfigFile($configPath);
        }
    }

    /**
     * Whether the current process runs from the command line.
     */
    private function isCli(): bool
    {
        return 'cli' === $this->sapi || 'phpdbg' === $this->sapi;
    }

    /**
     * Checks if the configuration is already defined in any config file.
     *
     * @param string $configDir The config directory path
     *
     * @return bool True if configuration exists, false otherwise
     */
    private function isConfigurationDefined(string $configDir): bool
    {
        if (!is_dir($configDir)) {
            return false;
        }

        $yamlFiles = glob($configDir . '/*.yaml') ?: [];
        $ymlFiles = glob($configDir . '/*.yml') ?: [];
        $files = array_merge($yamlFiles, $ymlFiles);
        $alias = Configuration::ALIAS;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content && str_contains($content, $alias . ':')) {
                return true;
            }
        }

        return false;
    }
}
