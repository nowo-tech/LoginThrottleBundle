<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\EventSubscriber;

use Nowo\LoginThrottleBundle\EventSubscriber\LoginConflictListener;
use Nowo\LoginThrottleBundle\Transaction\LoginThrottleStorageFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 3.16 (audit TJ-2): the login is ended and the commit mark removed above every renderer, whoever answers.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LoginConflictListenerTest extends TestCase
{
    private const MARK = '_nowo_transaction_doctrine_commit_mark';

    public function testItRunsAboveEveryRenderer(): void
    {
        $this->assertSame([KernelEvents::EXCEPTION => ['onException', 128]], LoginConflictListener::getSubscribedEvents());
    }

    public function testAConflictAfterARightPasswordEndsTheLogin(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security_main', 'serialized-token');
        $event = $this->event(LoginThrottleStorageFailure::DURING_SUCCESS, $session);
        $request = $event->getRequest();
        $request->attributes->set('_security_remember_me_cookie', 'cookie');

        (new LoginConflictListener($tokens))->onException($event);

        $this->assertNull($tokens->getToken());
        $this->assertFalse($session->has('_security_main'));
        $this->assertFalse($request->attributes->has('_security_remember_me_cookie'));
        $this->assertFalse($request->attributes->has(self::MARK), 'the commit mark of the real request is gone');
        $this->assertNull($event->getResponse(), 'it renders nothing');
    }

    public function testAConflictBeforeThePasswordCheckOnlyRemovesTheCommitMark(): void
    {
        $tokens = new TokenStorage();
        $token = new UsernamePasswordToken(new InMemoryUser('bob', null), 'main');
        $tokens->setToken($token);
        $session = new Session(new MockArraySessionStorage());
        $session->set('keep', 'me');
        $event = $this->event(LoginThrottleStorageFailure::DURING_ATTEMPT, $session);

        (new LoginConflictListener($tokens))->onException($event);

        $this->assertFalse($event->getRequest()->attributes->has(self::MARK));
        $this->assertSame($token, $tokens->getToken());
        $this->assertTrue($session->has('keep'));
    }

    public function testAFailureTheLimiterDidNotMarkIsUntouched(): void
    {
        $event = $this->event(null, null);

        (new LoginConflictListener())->onException($event);

        $this->assertTrue($event->getRequest()->attributes->has(self::MARK));
    }

    public function testWithoutSessionOrTokenStorageItStillWorks(): void
    {
        $event = $this->event(LoginThrottleStorageFailure::DURING_SUCCESS, null);

        (new LoginConflictListener())->onException($event);

        $this->assertFalse($event->getRequest()->attributes->has(self::MARK));
    }

    private function event(?string $mark, ?Session $session): ExceptionEvent
    {
        $request = Request::create('/login', 'POST');
        $request->attributes->set(self::MARK, new \stdClass());
        if (null !== $mark) {
            $request->attributes->set(LoginThrottleStorageFailure::REQUEST_ATTRIBUTE, $mark);
        }
        if ($session instanceof Session) {
            $request->setSession($session);
        }

        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new LoginThrottleStorageFailure());
    }
}
