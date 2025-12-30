<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\DependencyInjection;

use Nowo\LoginThrottleBundle\DependencyInjection\NowoLoginThrottleExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests for NowoLoginThrottleExtension.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class NowoLoginThrottleExtensionTest extends TestCase
{
    private NowoLoginThrottleExtension $extension;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new NowoLoginThrottleExtension();
        $this->container = new ContainerBuilder();
    }

    public function testGetAlias(): void
    {
        $this->assertSame('nowo_login_throttle', $this->extension->getAlias());
    }

    public function testLoadWithDefaultConfiguration(): void
    {
        $configs = [[]];

        $this->extension->load($configs, $this->container);

        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.config'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.enabled'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.max_attempts'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.interval'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.firewall'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.rate_limiter'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.cache_pool'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.lock_factory'));

        $this->assertTrue($this->container->getParameter('nowo_login_throttle.enabled'));
        $this->assertSame(3, $this->container->getParameter('nowo_login_throttle.max_attempts'));
        $this->assertSame('10 minutes', $this->container->getParameter('nowo_login_throttle.interval'));
        $this->assertSame('main', $this->container->getParameter('nowo_login_throttle.firewall'));
        $this->assertNull($this->container->getParameter('nowo_login_throttle.rate_limiter'));
        $this->assertSame('cache.rate_limiter', $this->container->getParameter('nowo_login_throttle.cache_pool'));
        $this->assertNull($this->container->getParameter('nowo_login_throttle.lock_factory'));
    }

    public function testLoadWithCustomConfiguration(): void
    {
        $configs = [
            [
                'enabled' => false,
                'max_count_attempts' => 5,
                'timeout' => 900,
                'firewall' => 'api',
                'rate_limiter' => 'custom_limiter',
                'cache_pool' => 'cache.custom',
                'lock_factory' => 'lock.factory',
            ],
        ];

        $this->extension->load($configs, $this->container);

        $this->assertFalse($this->container->getParameter('nowo_login_throttle.enabled'));
        $this->assertSame(5, $this->container->getParameter('nowo_login_throttle.max_attempts'));
        $this->assertSame('15 minutes', $this->container->getParameter('nowo_login_throttle.interval'));
        $this->assertSame('api', $this->container->getParameter('nowo_login_throttle.firewall'));
        $this->assertSame('custom_limiter', $this->container->getParameter('nowo_login_throttle.rate_limiter'));
        $this->assertSame('cache.custom', $this->container->getParameter('nowo_login_throttle.cache_pool'));
        $this->assertSame('lock.factory', $this->container->getParameter('nowo_login_throttle.lock_factory'));
    }

    public function testLoadStoresSecurityConfig(): void
    {
        $configs = [
            [
                'max_count_attempts' => 3,
                'timeout' => 600,
                'firewall' => 'main',
                'rate_limiter' => 'custom_limiter',
            ],
        ];

        $this->extension->load($configs, $this->container);

        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.security_config'));

        $securityConfig = $this->container->getParameter('nowo_login_throttle.security_config');
        $this->assertIsArray($securityConfig);
        $this->assertSame(3, $securityConfig['max_attempts']);
        $this->assertSame('10 minutes', $securityConfig['interval']);
        $this->assertSame('main', $securityConfig['firewall']);
        $this->assertSame('custom_limiter', $securityConfig['limiter']);
    }

    public function testLoadWhenDisabled(): void
    {
        $configs = [
            [
                'enabled' => false,
            ],
        ];

        $this->extension->load($configs, $this->container);

        $this->assertFalse($this->container->getParameter('nowo_login_throttle.enabled'));
        $this->assertTrue($this->container->hasParameter('nowo_login_throttle.security_config'));
    }

    public function testLoadWithAllOptions(): void
    {
        $configs = [
            [
                'enabled' => true,
                'max_count_attempts' => 5,
                'timeout' => 900,
                'watch_period' => 7200,
                'firewall' => 'api',
                'rate_limiter' => 'custom_limiter',
                'cache_pool' => 'cache.custom',
                'lock_factory' => 'lock.factory',
            ],
        ];

        $this->extension->load($configs, $this->container);

        $this->assertTrue($this->container->getParameter('nowo_login_throttle.enabled'));
        $this->assertSame(5, $this->container->getParameter('nowo_login_throttle.max_attempts'));
        $this->assertSame('15 minutes', $this->container->getParameter('nowo_login_throttle.interval'));
        $this->assertSame('api', $this->container->getParameter('nowo_login_throttle.firewall'));
        $this->assertSame('custom_limiter', $this->container->getParameter('nowo_login_throttle.rate_limiter'));
        $this->assertSame('cache.custom', $this->container->getParameter('nowo_login_throttle.cache_pool'));
        $this->assertSame('lock.factory', $this->container->getParameter('nowo_login_throttle.lock_factory'));

        $securityConfig = $this->container->getParameter('nowo_login_throttle.security_config');
        $this->assertSame('custom_limiter', $securityConfig['limiter']);
        $this->assertSame('cache.custom', $securityConfig['cache_pool']);
        $this->assertSame('lock.factory', $securityConfig['lock_factory']);
    }
}
