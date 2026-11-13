<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Doctrine\ExactCollationListener;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Tests\Fixtures\Entity\HostAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SEC-006 (3.10.2): the key columns get the NO PAD binary collation of the platform in the generated schema.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class ExactCollationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'mariadb' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin'];
        yield 'mysql 8.4' => [new MySQL84Platform(), 'utf8mb4_0900_bin'];
        yield 'mysql 8.0' => [new MySQL80Platform(), 'utf8mb4_0900_bin'];
        yield 'mysql 5.7 or server_version without patch' => [new MySQLPlatform(), 'utf8mb4_bin'];
        yield 'postgresql' => [new PostgreSQLPlatform(), null];
        yield 'sqlite' => [new SQLitePlatform(), null];
    }

    #[DataProvider('platforms')]
    public function testCollationFor(AbstractPlatform $platform, ?string $expected): void
    {
        $this->assertSame($expected, ExactCollationListener::collationFor($platform));
    }

    #[DataProvider('platforms')]
    public function testTheGeneratedSchemaCarriesTheCollation(AbstractPlatform $platform, ?string $expected): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects('utf8mb4_bin' === $expected ? $this->once() : $this->never())->method('warning');

        $table = $this->generatedTable($this->registryOn($platform), $logger);

        foreach (ExactCollationListener::COLUMNS as $column) {
            $this->assertSame($expected, self::collationOf($table, $column), $column);
        }
        $this->assertNull(self::collationOf($table, 'created_at'));
    }

    public function testWithoutAnOrmManagerNothingChanges(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $table = $this->generatedTable($registry, null);

        $this->assertNull(self::collationOf($table, 'username'));
    }

    public function testOtherEntitiesAreLeftAlone(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('getManagerForClass');
        $entityManager = $this->entityManager();
        $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchemaTable], new ExactCollationListener($registry));

        $schema = (new SchemaTool($entityManager))->getSchemaFromMetadata([$entityManager->getClassMetadata(HostAccount::class)]);

        $this->assertCount(1, $schema->getTables());
    }

    public function testCollateInPlaceOnOlderDbal(): void
    {
        $table = $this->generatedTable($this->registryOn(new SQLitePlatform()), null);

        $this->assertNull(ExactCollationListener::collate($table, 'username', 'utf8mb4_nopad_bin', false));
        $this->assertSame('utf8mb4_nopad_bin', self::collationOf($table, 'username'));
        $this->assertSame('utf8mb4', self::charsetOf($table, 'username'));
    }

    private function generatedTable(ManagerRegistry $registry, ?LoggerInterface $logger): Table
    {
        $entityManager = $this->entityManager();
        $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchemaTable], new ExactCollationListener($registry, $logger));

        $schema = (new SchemaTool($entityManager))->getSchemaFromMetadata([$entityManager->getClassMetadata(LoginAttempt::class)]);

        return $schema->getTable('login_attempts');
    }

    /**
     * @return iterable<string, array{AbstractPlatform, string|\Throwable, string}>
     */
    public static function serverVersions(): iterable
    {
        yield 'mysql 8.0.16 lacks utf8mb4_0900_bin' => [new MySQL80Platform(), '8.0.16', 'utf8mb4_bin'];
        yield 'mysql 8.0.17' => [new MySQL80Platform(), '8.0.17', 'utf8mb4_0900_bin'];
        yield 'mysql 8.0.40 with a suffix' => [new MySQL80Platform(), '8.0.40-0ubuntu0.24.04.1', 'utf8mb4_0900_bin'];
        yield 'unreadable version: the platform decides' => [new MySQL80Platform(), new \RuntimeException('offline'), 'utf8mb4_0900_bin'];
        yield 'unparsable version: the platform decides' => [new MySQL80Platform(), 'unknown', 'utf8mb4_0900_bin'];
        yield 'mysql 8.4 platform is not queried' => [new MySQL84Platform(), new \LogicException('must not be read'), 'utf8mb4_0900_bin'];
        yield 'mariadb is not queried' => [new MariaDBPlatform(), new \LogicException('must not be read'), 'utf8mb4_nopad_bin'];
    }

    #[DataProvider('serverVersions')]
    public function testCollationForConnectionChecksTheMySql80Patch(AbstractPlatform $platform, string|\Throwable $version, string $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        if ($version instanceof \Throwable) {
            $connection->method('getServerVersion')->willThrowException($version);
        } else {
            $connection->method('getServerVersion')->willReturn($version);
        }

        $this->assertSame($expected, ExactCollationListener::collationForConnection($connection));
    }

    /**
     * DBAL 3 keeps Connection::getServerVersion() private: the native connection is asked instead.
     */
    public function testServerVersionFallsBackToTheNativeConnection(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $dbal3 = new class($pdo) {
            public function __construct(private readonly mixed $native)
            {
            }

            public function getNativeConnection(): mixed
            {
                return $this->native;
            }
        };
        $this->assertSame($pdo->getAttribute(\PDO::ATTR_SERVER_VERSION), ExactCollationListener::serverVersion($dbal3));

        $unknownDriver = new $dbal3(new \stdClass());
        $this->assertNull(ExactCollationListener::serverVersion($unknownDriver));
        $this->assertNull(ExactCollationListener::serverVersion(new \stdClass()));
    }

    private static function collationOf(Table $table, string $column): ?string
    {
        $definition = $table->getColumn($column);
        if (method_exists($definition, 'getCollation')) {
            return $definition->getCollation();
        }

        $options = $definition->getPlatformOptions(); // DBAL 3

        return isset($options['collation']) && \is_string($options['collation']) ? $options['collation'] : null;
    }

    private static function charsetOf(Table $table, string $column): ?string
    {
        $definition = $table->getColumn($column);
        if (method_exists($definition, 'getCharset')) {
            return $definition->getCharset();
        }

        $options = $definition->getPlatformOptions(); // DBAL 3

        return isset($options['charset']) && \is_string($options['charset']) ? $options['charset'] : null;
    }

    private function registryOn(AbstractPlatform $platform): ManagerRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(LoginAttempt::class)->willReturn($manager);

        return $registry;
    }

    private function entityManager(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity', \dirname(__DIR__) . '/Fixtures/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration, new EventManager());
    }
}
