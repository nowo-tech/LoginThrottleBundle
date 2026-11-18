<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Integration;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Tests\Integration\Kernel\SecurityTestKernel;
use Nowo\LoginThrottleBundle\Tests\Support\FailingUnitStore;
use Nowo\LoginThrottleBundle\Tests\Support\HostConflictListeners;
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

        $this->assertSame(302, $this->next($session), 'the next request is anonymous: sent to the login');
    }

    public function testAConflictBeforeThePasswordCheckAnswers409WithoutLoggingIn(): void
    {
        [$session, $response] = $this->login('alice', 'right', 1);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($session->has('_security_main'));
    }

    /** audit TJ-2 LT2-1: with the answer left to the host, a right password plus a reset conflict still ends the login. */
    public function testHostModeEndsTheLoginToo(): void
    {
        [$session, $response] = $this->login('alice', 'right', 2, throttle: ['conflict_response' => ['mode' => 'host']]);

        $this->assertSame(500, $response->getStatusCode(), 'unmapped, the exception is the host\'s');
        $this->assertSame(302, $this->next($session), 'anonymous');
    }

    public function testAHostListenerAnsweringFirstStillEndsTheLogin(): void
    {
        [$session, $response] = $this->login('alice', 'right', 2, hostListener: true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('host answer', $response->getContent());
        $this->assertSame(302, $this->next($session));
    }

    public function testTheTransactionDoctrineResponderAnsweringFirstStillEndsTheLogin(): void
    {
        [$session, $response] = $this->login('alice', 'right', 2, transactionDoctrine: true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        $this->assertSame(302, $this->next($session));
    }

    /**
     * audit TJ-2 LT2-2: a host that commits only for existing accounts, with the shared responder mapping the failure:
     * the answers of an existing and of an unknown account are byte-identical, code 001.
     */
    public function testTheSharedResponderGivesTheSameAnswerWhateverTheHostCommitted(): void
    {
        $answers = [];
        foreach (['alice', 'nobody'] as $name) {
            [, $response] = $this->login($name, 'right', 1, transactionDoctrine: true, commitListener: true);
            $answers[] = [$response->getStatusCode(), $response->headers->get('Retry-After'), $response->headers->get('X-Conflict-Code'), $response->getContent()];
        }

        $this->assertSame($answers[0], $answers[1]);
        $this->assertSame(409, $answers[0][0]);
        $this->assertSame('CONFLICT_001', $answers[0][2]);
        $this->assertSame('1', $answers[0][1]);
    }

    private function next(Session $session): int
    {
        $next = Request::create('/home');
        $next->setSession($session);

        return $this->kernel?->handle($next)->getStatusCode() ?? 0;
    }

    /**
     * @param array<string, mixed> $throttle
     *
     * @return array{Session, Response}
     */
    private function login(string $username, string $password, ?int $failOnUnit, array $throttle = [], bool $hostListener = false, bool $transactionDoctrine = false, bool $commitListener = false): array
    {
        $this->kernel = new SecurityTestKernel('conflict-' . md5(serialize([$failOnUnit, $username, $throttle, $hostListener, $transactionDoctrine, $commitListener])), static function (ContainerBuilder $container) use ($hostListener, $commitListener): void {
            $container->register(HostConflictListeners::class, HostConflictListeners::class)
                ->setPublic(true)
                ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'render', 'priority' => 0]);
            $container->register('app.store', FailingUnitStore::class)->setPublic(true);
            $container->setAlias(LoginAttemptStoreInterface::class, 'app.store');
            if (!$hostListener) {
                $container->getDefinition(HostConflictListeners::class)->clearTag('kernel.event_listener');
            }
            if ($commitListener) {
                $container->getDefinition(HostConflictListeners::class)->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'commitForExistingAccounts', 'priority' => 0]);
            }
        }, $throttle, $transactionDoctrine);
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
