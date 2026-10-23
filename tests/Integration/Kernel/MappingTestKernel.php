<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\LoginThrottleBundle\NowoLoginThrottleBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Minimal host: FrameworkBundle + DoctrineBundle with ORM auto_mapping (SQLite in memory) + the bundle, configured with
 * the given nowo_login_throttle options.
 */
final class MappingTestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $throttleConfig nowo_login_throttle configuration
     * @param string               $caseId         Unique cache directory suffix
     */
    public function __construct(
        private readonly array $throttleConfig,
        private readonly string $caseId,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new NowoLoginThrottleBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->caseId . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->caseId . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-login-throttle-tests';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'login-throttle-test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => false],
            ]);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm' => ['auto_mapping' => true],
            ]);
            $container->loadFromExtension('nowo_login_throttle', $this->throttleConfig);
        });
    }
}
