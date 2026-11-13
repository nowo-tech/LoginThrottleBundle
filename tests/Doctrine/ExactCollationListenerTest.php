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
            $this->assertSame($expected, $table->getColumn($column)->getCollation(), $column);
        }
        $this->assertNull($table->getColumn('created_at')->getCollation());
    }

    public function testWithoutAnOrmManagerNothingChanges(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $table = $this->generatedTable($registry, null);

        $this->assertNull($table->getColumn('username')->getCollation());
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
        $this->assertSame('utf8mb4_nopad_bin', $table->getColumn('username')->getCollation());
        $this->assertSame('utf8mb4', $table->getColumn('username')->getCharset());
    }

    private function generatedTable(ManagerRegistry $registry, ?LoggerInterface $logger): Table
    {
        $entityManager = $this->entityManager();
        $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchemaTable], new ExactCollationListener($registry, $logger));

        $schema = (new SchemaTool($entityManager))->getSchemaFromMetadata([$entityManager->getClassMetadata(LoginAttempt::class)]);

        return $schema->getTable('login_attempts');
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
