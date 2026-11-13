<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRecorderInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepository;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRepositoryInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptRecorderStore;
use Nowo\LoginThrottleBundle\Tests\Support\InMemoryLoginAttemptStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rule 1 (3.12): the entity-free write twin {@see LoginAttemptRecorderInterface::recordAttemptView()} and the
 * limiter's preference for it over the deprecated entity-returning
 * {@see LoginAttemptRepositoryInterface::recordAttempt()}.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class LoginAttemptRecorderTest extends TestCase
{
    private EntityManager $entityManager;

    private ManagerRegistry&MockObject $registry;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2) . '/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        (new SchemaTool($this->entityManager))->createSchema([$this->entityManager->getClassMetadata(LoginAttempt::class)]);

        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')->willReturn($this->entityManager);
    }

    public function testTheBundleRepositoryImplementsTheWriteTwin(): void
    {
        $this->assertInstanceOf(LoginAttemptRecorderInterface::class, new LoginAttemptRepository($this->registry));
    }

    public function testRecordAttemptViewStoresTheRowAndReturnsItsView(): void
    {
        $repository = new LoginAttemptRepository($this->registry);

        $view = $repository->recordAttemptView('10.0.0.1', 'alice');
        $ipOnly = $repository->recordAttemptView('10.0.0.1', null);

        $this->assertInstanceOf(LoginAttemptView::class, $view);
        $this->assertSame(1, $view->id);
        $this->assertSame('10.0.0.1', $view->ipAddress);
        $this->assertSame('alice', $view->username);
        $this->assertFalse($view->blocked);
        $this->assertSame(2, $ipOnly->id);
        $this->assertNull($ipOnly->username);
        // no entity enters the identity map (worker safe), the rows are written
        $this->assertSame(0, $this->entityManager->getUnitOfWork()->size());
        $this->assertSame(
            [['ip_address' => '10.0.0.1', 'username' => 'alice'], ['ip_address' => '10.0.0.1', 'username' => null]],
            array_map(
                static fn (array $row): array => ['ip_address' => $row['ip_address'], 'username' => $row['username']],
                $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM login_attempts ORDER BY id'),
            ),
        );
    }

    public function testTheLimiterRecordsThroughTheWriteTwinWhenTheStoreImplementsIt(): void
    {
        $store = new InMemoryLoginAttemptRecorderStore();
        $limiter = new DatabaseRateLimiter($store, 3, 600);

        $this->assertTrue($limiter->consume(Request::create('/login', 'POST', ['_username' => 'alice'], server: ['REMOTE_ADDR' => '10.0.0.1']))->isAccepted());

        $this->assertSame(1, $store->recordAttemptViewCalls);
        $this->assertSame(0, $store->recordAttemptCalls);
        $this->assertSame(1, $store->countAttempts('10.0.0.1', 'alice', 600));
    }

    public function testTheLimiterFallsBackToTheDeprecatedWriteOnAStoreWithoutTheTwin(): void
    {
        $store = new InMemoryLoginAttemptStore();
        $limiter = new DatabaseRateLimiter($store, 3, 600);

        $this->assertTrue($limiter->consume(Request::create('/login', 'POST', ['_username' => 'bob'], server: ['REMOTE_ADDR' => '10.0.0.2']))->isAccepted());

        $this->assertSame(1, $store->countAttempts('10.0.0.2', 'bob', 600));
    }
}
