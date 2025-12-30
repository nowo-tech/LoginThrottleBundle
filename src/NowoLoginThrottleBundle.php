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
 * - Automatic configuration file generation
 * - Automatic security.yaml configuration
 * - Compatible with Symfony 6.0, 7.0, and 8.0
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
class NowoLoginThrottleBundle extends Bundle
{
    /**
     * Overridden to allow for the custom extension alias.
     *
     * Creates and returns the container extension instance if not already created.
     * The extension is cached after the first call to ensure the same instance is returned
     * on subsequent calls.
     *
     * @return ExtensionInterface|null The container extension instance, or null if not available
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new NowoLoginThrottleExtension();
        }

        return $this->extension;
    }

    /**
     * Generates the configuration file if it doesn't exist.
     *
     * This method is called during the kernel boot process. It checks if the
     * bundle configuration file exists, and if not, generates a default one.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        if (!$this->container->hasParameter('kernel.project_dir')) {
            return;
        }

        $projectDir = $this->container->getParameter('kernel.project_dir');
        $aliasBundle = Configuration::ALIAS;
        $configPath = $projectDir . sprintf('/config/packages/%s.yaml', $aliasBundle);
        $configDir = $projectDir . '/config/packages';

        // Check if the configuration already exists in any file
        if ($this->isConfigurationDefined($configDir)) {
            return;
        }

        // If it doesn't exist, create the configuration file
        if (!file_exists($configPath)) {
            $configuration = new Configuration();
            $configuration->generateConfigFile($configPath);
        }
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

        $files = glob($configDir . '/*.yaml') + glob($configDir . '/*.yml');
        $alias = Configuration::ALIAS;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content && strpos($content, $alias . ':') !== false) {
                return true;
            }
        }

        return false;
    }
}
