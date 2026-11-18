<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\EventSubscriber;

use Nowo\LoginThrottleBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\LoginThrottleBundle\Transaction\LoginThrottleStorageFailure;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6): 409 + Retry-After + CONFLICT_001 on the guarded logins, the same answer whatever the
 * account, never an allow.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class StorageFailureSubscriberTest extends TestCase
{
    public function testItListensOnTheExceptionEventAfterTheLogging(): void
    {
        $this->assertSame([KernelEvents::EXCEPTION => ['onException', -64]], StorageFailureSubscriber::getSubscribedEvents());
    }

    public function testAGuardedLoginAnswers409WithRetryAfterAndTheCode(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main', ['_username' => 'alice']);

        $this->subscriber(['main'])->onException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('CONFLICT_001', (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testAJsonLoginAnswersTheCodeInTheBody(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main', [], '/api/login', 'application/json');

        $this->subscriber(['main'])->onException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('CONFLICT_001', json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['code']);
    }

    /**
     * Not an oracle: an existing account, an unknown one, a locked one and a missing username all get the same answer,
     * whatever the failure carries (a storage error with the username in its message, another retry hint).
     */
    public function testTheAnswerIsTheSameWhateverTheAccountAndTheFailure(): void
    {
        $answers = [];
        foreach ([
            ['alice', new LoginThrottleStorageFailure(1, new \RuntimeException("deadlock on login_attempts for 'alice' from 10.0.0.1"))],
            ['nobody-by-that-name', new LoginThrottleStorageFailure(1, new \RuntimeException('lock wait timeout'))],
            ['ALICE', new LoginThrottleStorageFailure(9)],
            [null, new LoginThrottleStorageFailure(3)],
        ] as [$username, $failure]) {
            $event = $this->event($failure, 'main', null === $username ? [] : ['_username' => $username]);
            $this->subscriber(['main'])->onException($event);
            $response = $event->getResponse();
            $this->assertInstanceOf(Response::class, $response);
            $answers[] = [$response->getStatusCode(), $response->headers->get('Retry-After'), $response->getContent()];
        }

        $this->assertCount(1, array_unique(array_map(static fn (array $answer): string => serialize($answer), $answers)), 'one answer for every account');
        $this->assertStringNotContainsString('alice', (string) $answers[0][2]);
        $this->assertStringNotContainsString('10.0.0.1', (string) $answers[0][2]);
        $this->assertStringNotContainsString('deadlock', (string) $answers[0][2]);
    }

    /** A conflict is a refusal: the answer is never a success or a redirect. */
    public function testTheAnswerIsNeverASuccess(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main', ['_username' => 'alice', '_password' => 'right']);

        $this->subscriber(['main'])->onException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertFalse($response->isRedirection());
        $this->assertFalse($response->isSuccessful());
    }

    public function testTheStatusIsConfigurableTo503(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main');

        $this->subscriber(['main'], new ConflictResponsePolicy(503))->onException($event);

        $this->assertSame(503, $event->getResponse()?->getStatusCode());
        $this->assertSame('1', $event->getResponse()->headers->get('Retry-After'));
    }

    public function testAnUnguardedFirewallIsLeftToTheHost(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'admin');

        $this->subscriber(['main'])->onException($event);

        $this->assertNull($event->getResponse());
    }

    public function testWithoutTheSecurityServiceNothingIsAnswered(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main');

        (new StorageFailureSubscriber(null, ['main']))->onException($event);

        $this->assertNull($event->getResponse());
    }

    public function testARequestOutsideEveryFirewallIsLeftToTheHost(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), null);

        $this->subscriber(['main'])->onException($event);

        $this->assertNull($event->getResponse());
    }

    public function testAFailureOfTheHostIsLeftAlone(): void
    {
        foreach ([new TransientStorageFailure(1), new \RuntimeException('boom')] as $failure) {
            $event = $this->event($failure, 'main');

            $this->subscriber(['main'])->onException($event);

            $this->assertNull($event->getResponse());
        }
    }

    public function testAResponseOfAHostListenerWins(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main');
        $event->setResponse(new Response('maintenance', 503));

        $this->subscriber(['main'])->onException($event);

        $this->assertSame(503, $event->getResponse()?->getStatusCode());
        $this->assertSame('maintenance', $event->getResponse()->getContent());
    }

    public function testEveryAnswerCarriesTheConflictCodeHeaderAt409And503(): void
    {
        foreach ([[null, 409], [new ConflictResponsePolicy(503), 503]] as [$policy, $status]) {
            foreach ([['/login', null], ['/api/login', 'application/json']] as [$path, $accept]) {
                $event = $this->event(new LoginThrottleStorageFailure(), 'main', [], $path, $accept);

                $this->subscriber(['main'], $policy)->onException($event);

                $this->assertSame($status, $event->getResponse()?->getStatusCode());
                $this->assertSame('CONFLICT_001', $event->getResponse()->headers->get('X-Conflict-Code'));
            }
        }
    }

    /** F4: a failure host code raised on a guarded firewall (no mark of the limiter) is the host's. */
    public function testAFailureNotRaisedWhileAuthenticatingIsLeftToTheHost(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main', mark: null);

        $this->subscriber(['main'])->onException($event);

        $this->assertNull($event->getResponse());
    }

    /**
     * F3: transaction-doctrine 1.6 reads a commit mark of the request. A host that committed for an existing account would
     * get another code, so the responder never sees the mark: the code is 001 and the answer the same for everyone.
     */
    public function testTheCommitMarkOfTheRequestNeverReachesTheResponder(): void
    {
        $seen = [];
        $formatter = new class($seen) implements ConflictResponseFormatterInterface {
            /** @param list<bool> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function format(Request $request, ConflictResponseContext $context): ?Response
            {
                $this->seen[] = $request->attributes->has('_nowo_transaction_doctrine_commit_mark');

                return null;
            }
        };
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [$formatter]);
        $answers = [];
        foreach ([true, false] as $committedForThisAccount) {
            $event = $this->event(new LoginThrottleStorageFailure(), 'main', ['_username' => $committedForThisAccount ? 'alice' : 'nobody']);
            if ($committedForThisAccount) {
                $event->getRequest()->attributes->set('_nowo_transaction_doctrine_commit_mark', new \stdClass());
            }
            (new StorageFailureSubscriber($this->security, ['main'], null, $responder))->onException($event);
            $answers[] = [$event->getResponse()?->getStatusCode(), $event->getResponse()?->headers->get('X-Conflict-Code'), $event->getResponse()?->getContent()];
            $this->assertTrue($event->getRequest()->attributes->has('_nowo_transaction_doctrine_commit_mark') === $committedForThisAccount, 'the real request is untouched');
        }

        $this->assertSame([false, false], $seen);
        $this->assertSame($answers[0], $answers[1]);
        $this->assertSame('CONFLICT_001', $answers[0][1]);
    }

    /** F1: a conflict in the reset after a right password ends that login. */
    public function testAConflictAfterARightPasswordEndsTheLogin(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $event = $this->event(new LoginThrottleStorageFailure(), 'main', mark: LoginThrottleStorageFailure::DURING_SUCCESS);
        $request = $event->getRequest();
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security_main', 'serialized-token');
        $request->setSession($session);
        $request->attributes->set('_security_remember_me_cookie', 'cookie');

        (new StorageFailureSubscriber($this->security, ['main'], null, null, $tokens))->onException($event);

        $this->assertSame(409, $event->getResponse()?->getStatusCode());
        $this->assertNull($tokens->getToken());
        $this->assertFalse($session->has('_security_main'));
        $this->assertFalse($request->attributes->has('_security_remember_me_cookie'));
    }

    public function testAConflictAfterARightPasswordWithoutSessionOrTokenStorageStillAnswers(): void
    {
        $event = $this->event(new LoginThrottleStorageFailure(), 'main', mark: LoginThrottleStorageFailure::DURING_SUCCESS);

        $this->subscriber(['main'])->onException($event);

        $this->assertSame(409, $event->getResponse()?->getStatusCode());
    }

    /**
     * @param list<string> $firewalls
     */
    private function subscriber(array $firewalls, ?ConflictResponsePolicy $policy = null): StorageFailureSubscriber
    {
        return new StorageFailureSubscriber($this->security, $firewalls, $policy);
    }

    private ?MockObject $security = null;

    /**
     * @param array<string, string> $post
     */
    private function event(\Throwable $failure, ?string $firewall, array $post = [], string $path = '/login', ?string $accept = null, ?string $mark = LoginThrottleStorageFailure::DURING_ATTEMPT): ExceptionEvent
    {
        $request = Request::create($path, 'POST', $post, [], [], ['REMOTE_ADDR' => '10.0.0.1'] + (null === $accept ? [] : ['HTTP_ACCEPT' => $accept, 'CONTENT_TYPE' => $accept]));
        $request->attributes->set(LoginThrottleStorageFailure::REQUEST_ATTRIBUTE, $mark);
        $security = $this->createMock(Security::class);
        $security->method('getFirewallConfig')->willReturn(null === $firewall ? null : new FirewallConfig($firewall, 'user_checker'));
        $this->security = $security;

        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
