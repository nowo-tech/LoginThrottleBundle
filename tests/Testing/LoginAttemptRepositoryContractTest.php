<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Testing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Testing\LoginAttemptStoreContractTestCase;

/**
 * The bundle's Doctrine repository (SQLite) follows the store contract it documents.
 */
final class LoginAttemptRepositoryContractTest extends LoginAttemptStoreContractTestCase
{
    private Connection $connection;

    protected function createStore(): LoginAttemptStoreInterface
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(LoginAttempt::class)]);
        $this->connection = $entityManager->getConnection();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new LoginAttemptRepository($registry);
    }

    /**
     * The repository stamps rows with the system time: ageing the stored rows moves time forward for them.
     */
    protected function advance(int $seconds): void
    {
        $this->connection->executeStatement(\sprintf("UPDATE login_attempts SET created_at = datetime(created_at, '-%d seconds')", $seconds));
    }
}
