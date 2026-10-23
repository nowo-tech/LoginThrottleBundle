<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\DependencyInjection\NowoLoginThrottleExtension;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Tests\Integration\Kernel\MappingTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * doctrine.register_mapping in a booted host with Doctrine ORM auto_mapping.
 */
final class DoctrineMappingKernelTest extends TestCase
{
    private ?MappingTestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(MappingTestKernel::baseDir());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function mappingCases(): iterable
    {
        yield 'true, cache' => [['doctrine' => ['register_mapping' => true]], true];
        yield 'true, database' => [['doctrine' => ['register_mapping' => true], 'storage' => 'database'], true];
        yield 'false, cache' => [['doctrine' => ['register_mapping' => false]], false];
        yield 'auto, cache' => [['doctrine' => ['register_mapping' => 'auto']], false];
        yield 'auto, database' => [['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database'], true];
        yield 'auto, firewalls all cache' => [['doctrine' => ['register_mapping' => 'auto'], 'firewalls' => ['main' => ['storage' => 'cache'], 'api' => ['storage' => 'cache']]], false];
        yield 'auto, one database firewall' => [['doctrine' => ['register_mapping' => 'auto'], 'firewalls' => ['main' => ['storage' => 'cache'], 'api' => ['storage' => 'database']]], true];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('mappingCases')]
    public function testEntityIsMappedOnlyWhenEnabled(array $config, bool $mapped): void
    {
        $entityManager = $this->entityManager($config);

        $classes = array_map(
            static fn (ClassMetadata $metadata): string => $metadata->getName(),
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );

        self::assertSame($mapped, \in_array(LoginAttempt::class, $classes, true));
        self::assertSame($mapped, $this->container()->getParameter(NowoLoginThrottleExtension::MAPPING_ENABLED_PARAMETER));
    }

    public function testAutoWithCacheStorageNeedsNoTable(): void
    {
        $entityManager = $this->entityManager(['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'cache']);

        $sql = (new SchemaTool($entityManager))->getCreateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata());

        self::assertSame([], array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'login_attempts'))));
        self::assertFalse($this->container()->has(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID));

        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('nowo:login-throttle:cleanup'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('not available', $tester->getDisplay());
    }

    public function testAutoWithDatabaseStorageThrottlesThroughTheTable(): void
    {
        $entityManager = $this->entityManager(['doctrine' => ['register_mapping' => 'auto'], 'storage' => 'database']);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new LoginAttempt('127.0.0.1', 'user'));
        $entityManager->flush();

        $application = new Application($this->kernel());
        $tester = new CommandTester($application->find('nowo:login-throttle:cleanup'));

        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertTrue($this->container()->has(NowoLoginThrottleExtension::DATABASE_RATE_LIMITER_SERVICE_ID));
    }

    public function testFalseWithDatabaseStorageFailsAtContainerBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"nowo_login_throttle.doctrine.register_mapping: false" requires storage "cache"');

        $this->boot(['doctrine' => ['register_mapping' => false], 'storage' => 'database']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config): void
    {
        $this->kernel = new MappingTestKernel($config, md5(serialize($config)));
        $this->kernel->boot();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function entityManager(array $config): EntityManagerInterface
    {
        $this->boot($config);
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $entityManager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function kernel(): MappingTestKernel
    {
        self::assertNotNull($this->kernel);

        return $this->kernel;
    }

    private function container(): ContainerInterface
    {
        return $this->kernel()->getContainer();
    }
}
