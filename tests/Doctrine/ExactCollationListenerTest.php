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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\LoginThrottleBundle\Doctrine\ExactCollationListener;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Tests\Fixtures\Entity\HostAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SEC-006 (3.11.0): the key columns get the NO PAD binary collation of the platform in the generated schema.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class ExactCollationListenerTest extends TestCase
{
    /**
     * Connection parameters of a lazy connection (nothing connects: the platform comes from `serverVersion`).
     *
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function connections(): iterable
    {
        yield 'mariadb' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB'], 'utf8mb4_nopad_bin'];
        yield 'mysql 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3'], 'utf8mb4_0900_bin'];
        yield 'mysql 5.7 or server_version without patch' => [['driver' => 'pdo_mysql', 'serverVersion' => '5.7.44'], 'utf8mb4_bin'];
        yield 'sqlite' => [['driver' => 'pdo_sqlite', 'memory' => true], null];
    }

    /**
     * @return iterable<string, array{AbstractPlatform, string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'mariadb' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin'];
        if (class_exists(MySQL84Platform::class)) { // DBAL 4.1+
            yield 'mysql 8.4' => [new MySQL84Platform(), 'utf8mb4_0900_bin'];
        }
        yield 'mysql 8.0' => [new MySQL80Platform(), 'utf8mb4_0900_bin'];
        yield 'mysql 5.7 or server_version without patch' => [new MySQLPlatform(), 'utf8mb4_bin'];
        yield 'postgresql' => [new PostgreSQLPlatform(), null];
        yield 'sqlite' => [self::sqlitePlatform(), null];
    }

    #[DataProvider('platforms')]
    public function testCollationFor(AbstractPlatform $platform, ?string $expected): void
    {
        $this->assertSame($expected, ExactCollationListener::collationFor($platform));
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('connections')]
    public function testTheGeneratedSchemaCarriesTheCollation(array $params, ?string $expected): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects('utf8mb4_bin' === $expected ? $this->once() : $this->never())->method('warning');

        $table = $this->schema($params, new ExactCollationListener(null, $logger))->getTable('login_attempts');

        foreach (ExactCollationListener::COLUMNS as $column) {
            $this->assertSame($expected, self::collationOf($table, $column), $column);
        }
        $this->assertNull(self::collationOf($table, 'created_at'));
    }

    /**
     * Audit XI: the generated schema keeps every foreign key and index of every table, as without the listener.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('connections')]
    public function testEveryForeignKeyAndIndexIsKept(array $params, ?string $collation): void
    {
        $with = self::shape($this->schema($params, new ExactCollationListener()));
        $without = self::shape($this->schema($params, null));

        $this->assertSame($without, $with);
        $this->assertContains('idx_ip_username', $with['login_attempts']['indexes']);
        $this->assertSame($collation, self::collationOf($this->schema($params, new ExactCollationListener())->getTable('login_attempts'), 'username'));
    }

    /**
     * The real schema tool flow (ORM 3.7+ adds the deferred foreign keys after the table events): a host that maps a
     * to-one association onto the attempts table keeps its foreign key and index with the listener.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('connections')]
    public function testTheForeignKeyOfAHostAssociationIsKept(array $params, ?string $collation): void
    {
        $with = $this->schema($params, new ExactCollationListener(), true);
        $without = $this->schema($params, null, true);

        $this->assertContains('account_id->host_account(id) CASCADE', self::shape($without)['login_attempts']['fks']);
        $this->assertSame(self::shape($without), self::shape($with));
        $this->assertSame($collation, self::collationOf($with->getTable('login_attempts'), 'username'));
    }

    /**
     * A table that has foreign keys (this one has none today) keeps them, and the tables that point to it too.
     */
    public function testForeignKeysOfTheEditedTableSurviveTheSwap(): void
    {
        $entityManager = $this->entityManager(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3']);
        $schema = new Schema();
        $owner = $schema->createTable('host_account');
        $owner->addColumn('id', 'integer');
        $owner->setPrimaryKey(['id']);
        $attempts = $schema->createTable('login_attempts');
        $attempts->addColumn('id', 'integer');
        $attempts->addColumn('username', 'string', ['length' => 255]);
        $attempts->addColumn('ip_address', 'string', ['length' => 45]);
        $attempts->addColumn('account_id', 'integer', ['notnull' => false]);
        $attempts->setPrimaryKey(['id']);
        $attempts->addIndex(['account_id'], 'idx_attempt_account');
        $attempts->addForeignKeyConstraint('host_account', ['account_id'], ['id'], ['onDelete' => 'CASCADE']);
        $pointer = $schema->createTable('host_pointer');
        $pointer->addColumn('attempt_id', 'integer');
        $pointer->addForeignKeyConstraint('login_attempts', ['attempt_id'], ['id']);
        $before = self::shape($schema);

        $args = new GenerateSchemaEventArgs($entityManager, $schema);
        (new ExactCollationListener())->postGenerateSchema($args);

        $this->assertSame($before, self::shape($args->getSchema()));
        $this->assertSame('utf8mb4_0900_bin', self::collationOf($args->getSchema()->getTable('login_attempts'), 'username'));
        $this->assertSame('utf8mb4_0900_bin', self::collationOf($args->getSchema()->getTable('login_attempts'), 'ip_address'));
    }

    public function testAManagerWithoutTheBundleEntityIsLeftAlone(): void
    {
        $entityManager = $this->entityManager(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3'], [\dirname(__DIR__) . '/Fixtures/Entity']);
        $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchema], new ExactCollationListener());

        $schema = (new SchemaTool($entityManager))->getSchemaFromMetadata([$entityManager->getClassMetadata(HostAccount::class)]);

        $this->assertCount(1, $schema->getTables());
    }

    public function testAManagerThatDoesNotMapTheBundleEntityIsSkipped(): void
    {
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(true);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getMetadataFactory')->willReturn($factory);
        $schema = new Schema();
        $schema->createTable('login_attempts')->addColumn('username', 'string', ['length' => 255]);

        $args = new GenerateSchemaEventArgs($manager, $schema);
        (new ExactCollationListener())->postGenerateSchema($args);

        $this->assertSame($schema, $args->getSchema());
        $this->assertNull(self::collationOf($schema->getTable('login_attempts'), 'username'));
    }

    public function testTheFormerTableEventDoesNothing(): void
    {
        $table = new Table('login_attempts');
        $table->addColumn('username', 'string', ['length' => 255]);
        $args = new GenerateSchemaTableEventArgs(new ClassMetadata(LoginAttempt::class), new Schema(), $table);

        (new ExactCollationListener())->postGenerateSchemaTable($args);

        $this->assertSame($table, $args->getClassTable());
        $this->assertNull(self::collationOf($table, 'username'));
    }

    public function testCollateInPlaceOnOlderDbal(): void
    {
        $table = $this->schema(['driver' => 'pdo_sqlite', 'memory' => true], null)->getTable('login_attempts');

        $this->assertNull(ExactCollationListener::collate($table, 'username', 'utf8mb4_nopad_bin', false));
        $this->assertSame('utf8mb4_nopad_bin', self::collationOf($table, 'username'));
        $this->assertSame('utf8mb4', self::charsetOf($table, 'username'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function schema(array $params, ?ExactCollationListener $listener, bool $hostAssociation = false): Schema
    {
        $entityManager = $this->entityManager($params);
        if ($listener instanceof ExactCollationListener) {
            $entityManager->getEventManager()->addEventListener([ToolEvents::postGenerateSchema], $listener);
        }

        $classes = $entityManager->getMetadataFactory()->getAllMetadata();
        if ($hostAssociation) {
            // a host that maps a to-one association onto the attempts table (metadata built by hand: the entity has no such property)
            $classes = array_map(static function (ClassMetadata $metadata): ClassMetadata {
                if (LoginAttempt::class !== $metadata->getName()) {
                    return $metadata;
                }
                $metadata = clone $metadata;
                $metadata->mapManyToOne(['fieldName' => 'account', 'targetEntity' => HostAccount::class, 'joinColumns' => [['name' => 'account_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']]]);

                return $metadata;
            }, $classes);
        }

        return (new SchemaTool($entityManager))->getSchemaFromMetadata($classes);
    }

    /**
     * Foreign keys (local columns, foreign table and columns, ON DELETE) and indexes per table.
     *
     * @return array<string, array{fks: list<string>, indexes: list<string>}>
     */
    private static function shape(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->getTables() as $table) {
            $fks = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fks[] = implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName() . '(' . implode(',', $fk->getForeignColumns()) . ') ' . ($fk->getOption('onDelete') ?? '-');
            }
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[] = $index->getName();
            }
            sort($fks);
            sort($indexes);
            $shape[$table->getName()] = ['fks' => $fks, 'indexes' => $indexes];
        }
        ksort($shape);

        return $shape;
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
        if (class_exists(MySQL84Platform::class)) { // DBAL 4.1+
            yield 'mysql 8.4 platform is not queried' => [new MySQL84Platform(), new \LogicException('must not be read'), 'utf8mb4_0900_bin'];
        }
        yield 'mariadb is not queried' => [new MariaDBPlatform(), new \LogicException('must not be read'), 'utf8mb4_nopad_bin'];
    }

    #[DataProvider('serverVersions')]
    public function testCollationForConnectionChecksTheMySql80Patch(AbstractPlatform $platform, string|\Throwable $version, string $expected): void
    {
        if (!(new \ReflectionMethod(Connection::class, 'getServerVersion'))->isPublic()) {
            $this->markTestSkipped('DBAL 3 keeps Connection::getServerVersion() private (covered by the native-connection test).');
        }
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

    /**
     * @param array<string, mixed> $params
     * @param list<string>|null    $paths
     */
    private function entityManager(array $params, ?array $paths = null): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration($paths ?? [\dirname(__DIR__, 2) . '/src/Entity', \dirname(__DIR__) . '/Fixtures/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection($params, $configuration), $configuration, new EventManager());
    }

    /**
     * SQLitePlatform on DBAL 4, SqlitePlatform on DBAL 3 (CI job "Load readiness" runs the suite on both).
     */
    private static function sqlitePlatform(): SQLitePlatform
    {
        $class = class_exists(SQLitePlatform::class) ? SQLitePlatform::class : 'Doctrine\\DBAL\\Platforms\\SqlitePlatform';

        return new $class();
    }
}
