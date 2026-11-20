<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\LoginThrottleBundle\Doctrine\ExactCollationListener;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit XI on real servers (MySQL 8.4 and MariaDB 11.4 in CI; PostgreSQL where none runs): the schema created with the
 * collation listener keeps a foreign key (and its index) that starts on an edited table, and the key columns get the
 * exact-match collation of the server. The association is built by hand (the entity has no such property); with the
 * listener on `postGenerateSchemaTable` (until the fix) ORM 3.7–3.8 lost it on DBAL 4.5+.
 */
#[Group('database')]
final class SchemaCollationDatabaseTest extends TestCase
{
    private ?EntityManager $manager = null;

    /** @var list<ClassMetadata<object>> */
    private array $classes = [];

    protected function tearDown(): void
    {
        if ($this->manager instanceof EntityManager) {
            (new SchemaTool($this->manager))->dropSchema($this->classes);
            $this->manager->getConnection()->close();
        }
        $this->manager = null;
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function databases(): iterable
    {
        foreach (['mysql' => 'LOGIN_THROTTLE_TEST_MYSQL_URL', 'mariadb' => 'LOGIN_THROTTLE_TEST_MARIADB_URL'] as $name => $variable) {
            $url = \getenv($variable);
            yield $name => [\is_string($url) && '' !== $url ? $url : null];
        }
    }

    #[DataProvider('databases')]
    public function testTheCreatedSchemaKeepsTheForeignKeyAndGetsTheCollation(?string $url): void
    {
        if (null === $url) {
            self::markTestSkipped('Set LOGIN_THROTTLE_TEST_MYSQL_URL and LOGIN_THROTTLE_TEST_MARIADB_URL to run the real-database test.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $events = new EventManager();
        $events->addEventListener(ToolEvents::postGenerateSchema, new ExactCollationListener());
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']))->parse($url);
        $manager = new EntityManager(DriverManager::getConnection($params, $config), $config, $events);
        $this->manager = $manager;

        $first = LoginAttempt::class;
        $this->classes = \array_map(static function (ClassMetadata $metadata) use ($first): ClassMetadata {
            if ($metadata->getName() !== $first) {
                return $metadata;
            }
            $metadata = clone $metadata;
            $metadata->mapManyToOne(['fieldName' => 'parent', 'targetEntity' => $first, 'joinColumns' => [['name' => 'parent_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']]]);

            return $metadata;
        }, \array_values($manager->getMetadataFactory()->getAllMetadata()));
        $tool = new SchemaTool($manager);
        $tool->dropSchema($this->classes);
        $tool->createSchema($this->classes);

        $connection = $manager->getConnection();
        $table = $manager->getClassMetadata($first)->getTableName();
        $schemaManager = $connection->createSchemaManager();
        $foreignKeys = [];
        foreach ($schemaManager->listTableForeignKeys($table) as $foreignKey) {
            $foreignKeys[] = \strtolower(\implode(',', $foreignKey->getUnquotedLocalColumns()) . '->' . $foreignKey->getForeignTableName() . ' ' . ($foreignKey->getOption('onDelete') ?? '-'));
        }
        self::assertContains('parent_id->' . $table . ' cascade', $foreignKeys);
        $indexed = false;
        foreach ($schemaManager->listTableIndexes($table) as $index) {
            $indexed = $indexed || ['parent_id'] === \array_map(\strtolower(...), $index->getUnquotedColumns());
        }
        self::assertTrue($indexed, 'the index of the foreign key');

        $platform = $connection->getDatabasePlatform();
        $collation = ExactCollationListener::collationFor($platform);
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::assertNull($collation);

            return;
        }
        foreach ([[LoginAttempt::class, 'username'], [LoginAttempt::class, 'ip_address']] as [$class, $column]) {
            self::assertSame(
                $collation,
                $connection->fetchOne('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$manager->getClassMetadata($class)->getTableName(), $column]),
                $class . '.' . $column,
            );
        }
    }
}
