<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests;

use Nowo\LoginThrottleBundle\DependencyInjection\NowoLoginThrottleExtension;
use Nowo\LoginThrottleBundle\NowoLoginThrottleBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * Tests for NowoLoginThrottleBundle.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class NowoLoginThrottleBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsInstance(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $extension = $bundle->getContainerExtension();

        $this->assertInstanceOf(ExtensionInterface::class, $extension);
        $this->assertInstanceOf(NowoLoginThrottleExtension::class, $extension);
        $this->assertNotNull($extension);
    }

    public function testGetContainerExtensionReturnsSameInstance(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $extension1 = $bundle->getContainerExtension();
        $extension2 = $bundle->getContainerExtension();

        $this->assertSame($extension1, $extension2);
    }

    public function testGetContainerExtensionAlias(): void
    {
        $bundle = new NowoLoginThrottleBundle();
        $extension = $bundle->getContainerExtension();

        $this->assertInstanceOf(ExtensionInterface::class, $extension);
        $this->assertSame('nowo_login_throttle', $extension->getAlias());
    }

    public function testGetContainerExtensionInitializesOnlyOnce(): void
    {
        $bundle = new NowoLoginThrottleBundle();

        // First call should create the extension
        $extension1 = $bundle->getContainerExtension();
        $this->assertNotNull($extension1);

        // Second call should return the same instance (already initialized)
        $extension2 = $bundle->getContainerExtension();
        $this->assertSame($extension1, $extension2);
    }
}
