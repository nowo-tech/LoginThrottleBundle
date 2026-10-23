<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\DependencyInjection;

use Nowo\LoginThrottleBundle\DependencyInjection\NowoLoginThrottleExtension;
use Nowo\LoginThrottleBundle\Doctrine\TablePrefixListener;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiterFactory;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Tests for nowo_login_throttle.doctrine.register_mapping (true | false | auto).
 */
final class RegisterMappingTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function mappingCases(): iterable
    {
        yield 'default, cache' => [[], true];
        yield 'default, database' => [['storage' => 'database'], true];
        yield 'true, cache' => [['doctrine' => ['register_mapping' => true]], true];
        yield 'true, database' => [['doctrine' => ['register_mapping' => true], 'storage' => 'database'], true];
        yield 'false, cache' => [['doctrine' => ['register_mapping' => false]], false];
        yield 'auto, cache' => [['doctrine' => ['register_mapping' => 'auto']], false];
        yield 'auto, database' => [['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database'], true];
        yield 'auto, disabled database' => [['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database', 'enabled' => false], true];
        yield 'auto, firewalls all cache' => [['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database', 'firewalls' => ['main' => ['storage' => 'cache'], 'api' => []]], false];
        yield 'auto, one database firewall' => [['doctrine' => ['register_mapping' => 'auto'], 'firewalls' => ['main' => ['storage' => 'cache'], 'api' => ['storage' => 'database']]], true];
        yield 'false, firewalls all cache' => [['doctrine' => ['register_mapping' => false], 'firewalls' => ['main' => ['storage' => 'cache']]], false];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('mappingCases')]
    public function testResolvesMapping(array $config, bool $mapped): void
    {
        $container = $this->load($config);

        self::assertSame($mapped, $container->getParameter(NowoLoginThrottleExtension::MAPPING_ENABLED_PARAMETER));
        self::assertSame($mapped, $container->hasDefinition(LoginAttemptRepository::class));
        self::assertSame($mapped, $container->hasAlias(LoginAttemptRepositoryInterface::class));
        self::assertSame($mapped, $container->hasDefinition(DatabaseRateLimiterFactory::class));
    }

    public function testUnmappedRemovesTheTablePrefixListener(): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => 'auto', 'table_prefix' => 'app_']]);

        self::assertFalse($container->hasDefinition(TablePrefixListener::class));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function falseWithDatabaseCases(): iterable
    {
        yield 'single firewall' => [['storage' => 'database']];
        yield 'multiple firewalls' => [['firewalls' => ['main' => ['storage' => 'cache'], 'api' => ['storage' => 'database']]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('falseWithDatabaseCases')]
    public function testFalseRejectsDatabaseStorage(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"nowo_login_throttle.doctrine.register_mapping: false" requires storage "cache"');

        $this->load(['doctrine' => ['register_mapping' => false]] + $config);
    }

    public function testRejectsUnknownValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['doctrine' => ['register_mapping' => 'sometimes']]);
    }

    public function testPrependOptsOutOfAutoMappingOnTheAutoMappedManager(): void
    {
        $container = $this->containerWithDoctrine([
            ['orm' => ['entity_managers' => ['legacy' => ['auto_mapping' => false], 'main' => ['auto_mapping' => true], 'broken' => 'invalid']]],
        ]);
        $container->prependExtensionConfig('nowo_login_throttle', ['doctrine' => ['register_mapping' => false]]);

        (new NowoLoginThrottleExtension())->prepend($container);

        self::assertSame(
            ['orm' => ['entity_managers' => ['main' => ['mappings' => ['NowoLoginThrottleBundle' => ['mapping' => false]]]]]],
            $container->getExtensionConfig('doctrine')[0],
        );
    }

    public function testPrependUsesTheDefaultEntityManagerOfTheShortcutLayout(): void
    {
        $container = $this->containerWithDoctrine([['dbal' => []], ['orm' => ['auto_mapping' => true]], ['orm' => ['default_entity_manager' => 'app']]]);
        $this->configureThrottle($container, [
            ['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database', 'firewalls' => ['main' => ['storage' => 'database']]],
            ['storage' => 'cache', 'firewalls' => ['main' => ['storage' => 'cache'], 'api' => null]],
        ]);

        (new NowoLoginThrottleExtension())->prepend($container);

        self::assertSame(
            ['orm' => ['entity_managers' => ['app' => ['mappings' => ['NowoLoginThrottleBundle' => ['mapping' => false]]]]]],
            $container->getExtensionConfig('doctrine')[0],
        );
    }

    public function testPrependFallsBackToTheDefaultManager(): void
    {
        $container = $this->containerWithDoctrine([['orm' => ['auto_mapping' => true]]]);
        $this->configureThrottle($container, [['doctrine' => ['register_mapping' => 'auto']]]);

        (new NowoLoginThrottleExtension())->prepend($container);

        self::assertSame(
            ['orm' => ['entity_managers' => ['default' => ['mappings' => ['NowoLoginThrottleBundle' => ['mapping' => false]]]]]],
            $container->getExtensionConfig('doctrine')[0],
        );
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, list<array<string, mixed>>}>
     */
    public static function prependSkipCases(): iterable
    {
        $orm = [['orm' => ['auto_mapping' => true]]];

        yield 'mapping kept by default' => [$orm, []];
        yield 'true' => [$orm, [['doctrine' => ['register_mapping' => true]]]];
        yield 'auto with database storage' => [$orm, [['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database']]];
        yield 'auto with a database firewall' => [$orm, [['doctrine' => ['register_mapping' => 'auto']], ['firewalls' => ['api' => ['storage' => 'database']]]]];
        yield 'host maps the bundle' => [[['orm' => ['mappings' => ['NowoLoginThrottleBundle' => ['mapping' => true]]]]], [['doctrine' => ['register_mapping' => false]]]];
        yield 'host maps the bundle on a manager' => [[['orm' => ['entity_managers' => ['default' => ['mappings' => ['NowoLoginThrottleBundle' => ['mapping' => true]]]]]]], [['doctrine' => ['register_mapping' => false]]]];
        yield 'dbal only' => [[['dbal' => ['url' => 'sqlite:///:memory:']]], [['doctrine' => ['register_mapping' => false]]]];
    }

    /**
     * @param list<array<string, mixed>> $doctrine
     * @param list<array<string, mixed>> $throttle
     */
    #[DataProvider('prependSkipCases')]
    public function testPrependLeavesDoctrineAlone(array $doctrine, array $throttle): void
    {
        $container = $this->containerWithDoctrine($doctrine);
        $this->configureThrottle($container, $throttle);

        (new NowoLoginThrottleExtension())->prepend($container);

        self::assertSame($doctrine, $container->getExtensionConfig('doctrine'));
    }

    public function testPrependWithoutDoctrineExtensionIsANoOp(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('nowo_login_throttle', ['doctrine' => ['register_mapping' => false]]);

        (new NowoLoginThrottleExtension())->prepend($container);

        self::assertFalse($container->hasExtension('doctrine'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new NowoLoginThrottleExtension())->load([$config], $container);

        return $container;
    }

    /**
     * @param list<array<string, mixed>> $configs In loading order
     */
    private function configureThrottle(ContainerBuilder $container, array $configs): void
    {
        foreach (array_reverse($configs) as $config) {
            $container->prependExtensionConfig('nowo_login_throttle', $config);
        }
    }

    /**
     * @param list<array<string, mixed>> $configs In loading order
     */
    private function containerWithDoctrine(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'doctrine';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        foreach (array_reverse($configs) as $config) {
            $container->prependExtensionConfig('doctrine', $config);
        }

        return $container;
    }
}
