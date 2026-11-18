<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\LoginThrottleBundle\NowoLoginThrottleBundle;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * A host with a form_login firewall throttled by the bundle (storage database through a host store), for the end-to-end
 * tests of the conflict answer (REQ-RUNTIME-003 rule 6).
 */
final class SecurityTestKernel extends Kernel
{
    /**
     * @param \Closure(ContainerBuilder): void $services Host services and aliases
     */
    /**
     * @param array<string, mixed> $throttle            nowo_login_throttle options added to the defaults
     * @param bool                 $transactionDoctrine Register NowoTransactionDoctrineBundle with map_transient_failures
     */
    public function __construct(private readonly string $caseId, private readonly \Closure $services, private readonly array $throttle = [], private readonly bool $transactionDoctrine = false)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new NowoLoginThrottleBundle();
        if ($this->transactionDoctrine) {
            yield new NowoTransactionDoctrineBundle();
        }
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return MappingTestKernel::baseDir() . '/' . $this->caseId . '/cache';
    }

    public function getLogDir(): string
    {
        return MappingTestKernel::baseDir() . '/' . $this->caseId . '/log';
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
                'router' => ['resource' => __DIR__ . '/routes.yaml', 'type' => 'yaml', 'utf8' => true],
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            ]);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm' => ['auto_mapping' => true],
            ]);
            $container->loadFromExtension('security', [
                'password_hashers' => [InMemoryUser::class => 'plaintext'],
                'providers' => ['users' => ['memory' => ['users' => ['alice' => ['password' => 'right', 'roles' => ['ROLE_USER']]]]]],
                'firewalls' => ['main' => [
                    'provider' => 'users',
                    'form_login' => ['login_path' => '/login', 'check_path' => '/login'],
                    'login_throttling' => ['limiter' => 'nowo_login_throttle.database_rate_limiter'],
                ]],
                'access_control' => [['path' => '^/home', 'roles' => 'ROLE_USER']],
            ]);
            $container->loadFromExtension('nowo_login_throttle', $this->throttle + [
                'doctrine' => ['register_mapping' => false],
                'storage' => 'database',
                'max_count_attempts' => 5,
                'firewall' => 'main',
            ]);
            if ($this->transactionDoctrine) {
                $container->loadFromExtension('nowo_transaction_doctrine', ['conflict_response' => ['enabled' => true, 'map_transient_failures' => true]]);
            }
            $container->register(SecurityTestController::class, SecurityTestController::class)->setPublic(true)->addTag('controller.service_arguments');
            ($this->services)($container);
        });
    }
}
