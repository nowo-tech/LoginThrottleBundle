<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\LoginThrottleBundle\Doctrine\ExactCollationListener;
use Nowo\LoginThrottleBundle\Doctrine\LoginThrottleExactKeyColumns;
use Nowo\LoginThrottleBundle\Doctrine\TablePrefixListener;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;

/**
 * REQ-SEC-006 on a real server: the bundle's own gate over its own declaration.
 *
 * The schema is created the way a host creates it (the schema tool, with the prefix and collation listeners the
 * bundle registers), under a table prefix, and the declared columns are then read back from `information_schema`: a
 * key column left on the server default — or a new one nobody collated — fails *this bundle's* CI, long before any
 * host installs it. The prefix is deliberately not empty, so the run also proves the declaration resolves the
 * configured table name instead of a literal.
 */
abstract class ExactKeyColumnsDatabaseTestCase extends ExactKeyColumnsTestCase
{
    /** A non-empty prefix: the declaration must follow the configured table name. */
    protected const PREFIX = 'exactkey_';

    protected function exactKeyColumnProviders(): array
    {
        return [new LoginThrottleExactKeyColumns(self::PREFIX)];
    }

    protected function createSchema(Connection $connection): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener(self::PREFIX));
        $events->addEventListener(ToolEvents::postGenerateSchema, new ExactCollationListener());

        $manager = new EntityManager($connection, $config, $events);
        /** @var list<ClassMetadata<object>> $classes */
        $classes = array_values($manager->getMetadataFactory()->getAllMetadata());

        $tool = new SchemaTool($manager);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
