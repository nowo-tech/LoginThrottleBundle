<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\EventSubscriber;

use Nowo\LoginThrottleBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\LoginThrottleBundle\Transaction\LoginThrottleStorageFailure;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

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
    private function event(\Throwable $failure, ?string $firewall, array $post = [], string $path = '/login', ?string $accept = null): ExceptionEvent
    {
        $request = Request::create($path, 'POST', $post, [], [], ['REMOTE_ADDR' => '10.0.0.1'] + (null === $accept ? [] : ['HTTP_ACCEPT' => $accept, 'CONTENT_TYPE' => $accept]));
        $security = $this->createMock(Security::class);
        $security->method('getFirewallConfig')->willReturn(null === $firewall ? null : new FirewallConfig($firewall, 'user_checker'));
        $this->security = $security;

        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
