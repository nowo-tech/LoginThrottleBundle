<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Tests\Integration\Kernel\SecurityTestKernel;
use Nowo\LoginThrottleBundle\Tests\Support\FailingUnitStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6) through a real firewall: a conflict never lets a login through, also after a right
 * password.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictAnswerKernelTest extends TestCase
{
    private ?SecurityTestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testARightPasswordWithoutConflictLogsIn(): void
    {
        [$session, $response] = $this->login('alice', 'right', null);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($session->has('_security_main'), 'control: the token is saved in the session');
    }

    /** F1: the reset after the right password lost its lock: 409, and the next request is not authenticated. */
    public function testAConflictAfterARightPasswordDoesNotCompleteTheLogin(): void
    {
        [$session, $response] = $this->login('alice', 'right', 2);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
        $this->assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        $this->assertFalse($session->has('_security_main'), 'no token in the session');

        $next = Request::create('/home');
        $next->setSession($session);
        $this->assertSame(302, $this->kernel?->handle($next)->getStatusCode(), 'the next request is anonymous: sent to the login');
    }

    public function testAConflictBeforeThePasswordCheckAnswers409WithoutLoggingIn(): void
    {
        [$session, $response] = $this->login('alice', 'right', 1);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($session->has('_security_main'));
    }

    /**
     * @return array{Session, Response}
     */
    private function login(string $username, string $password, ?int $failOnUnit): array
    {
        $this->kernel = new SecurityTestKernel('conflict-' . md5($failOnUnit . $username), static function (ContainerBuilder $container): void {
            $container->register('app.store', FailingUnitStore::class)->setPublic(true);
            $container->setAlias(LoginAttemptStoreInterface::class, 'app.store');
        });
        $this->kernel->boot();
        $store = $this->kernel->getContainer()->get('app.store');
        $this->assertInstanceOf(FailingUnitStore::class, $store);
        $store->failOnUnit = $failOnUnit;

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/login', 'POST', ['_username' => $username, '_password' => $password], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $request->setSession($session);

        return [$session, $this->kernel->handle($request)];
    }
}
