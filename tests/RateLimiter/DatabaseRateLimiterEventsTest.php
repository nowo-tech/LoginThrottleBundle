<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\RateLimiter;

use Nowo\LoginThrottleBundle\Event\LoginAttemptsClearedEvent;
use Nowo\LoginThrottleBundle\Event\LoginThrottledEvent;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiter;
use Nowo\LoginThrottleBundle\RateLimiter\DatabaseRateLimiterFactory;
use Nowo\LoginThrottleBundle\Tests\Support\EntityFreeLoginAttemptStore;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * LoginThrottledEvent / LoginAttemptsClearedEvent (3.14, REQ-ARCH-001 rules 4 and 7) on an entity-free host store.
 */
final class DatabaseRateLimiterEventsTest extends TestCase
{
    private EntityFreeLoginAttemptStore $store;

    /** @var list<object> */
    private array $events = [];

    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->store = new EntityFreeLoginAttemptStore();
        $this->events = [];
        $events = &$this->events;
        $store = $this->store;
        $this->dispatcher = new class($events, $store) implements EventDispatcherInterface {
            /**
             * @param list<object> $events
             */
            public function __construct(private array &$events, private readonly EntityFreeLoginAttemptStore $store)
            {
            }

            public function dispatch(object $event): object
            {
                // what the store holds when listeners run: the refused attempt is already written
                $this->events[] = [$event, $this->store->countAttemptsByIp('10.0.0.1', 3600)];

                return $event;
            }
        };
    }

    public function testRecordedRefusalStartsTheLockoutThenRefusalsWhileLockedDoNot(): void
    {
        $limiter = $this->limiter(maxAttempts: 2);

        self::assertTrue($limiter->consume($this->request('Alice'))->isAccepted());
        self::assertSame([], $this->events, 'an accepted attempt dispatches nothing');

        self::assertFalse($limiter->consume($this->request('Alice'))->isAccepted());
        self::assertFalse($limiter->consume($this->request('alice'))->isAccepted());

        self::assertCount(2, $this->events);
        [[$first, $storedAtFirst], [$second, $storedAtSecond]] = $this->events;
        self::assertInstanceOf(LoginThrottledEvent::class, $first);
        self::assertInstanceOf(LoginThrottledEvent::class, $second);

        self::assertSame('10.0.0.1', $first->ipAddress);
        self::assertSame('alice', $first->username, 'normalised username');
        self::assertSame(LoginThrottledEvent::SCOPE_USERNAME, $first->scope);
        self::assertSame(2, $first->limit);
        self::assertTrue($first->lockoutStarted);
        self::assertSame(2, $storedAtFirst, 'dispatched after the attempt was written');
        self::assertSame($this->store->now->getTimestamp() + 600, $first->retryAfter->getTimestamp());
        self::assertSame('main', $first->firewallName);

        self::assertFalse($second->lockoutStarted, 'refused while locked: not recorded');
        self::assertSame(2, $storedAtSecond);
        self::assertSame(LoginThrottledEvent::SCOPE_USERNAME, $second->scope);
    }

    public function testIpCapScopeReportsThePerIpLimit(): void
    {
        $limiter = $this->limiter(maxAttempts: 5, maxAttemptsPerIp: 2);

        $limiter->consume($this->request('a'));
        $limiter->consume($this->request('b'));
        $limiter->consume($this->request('c'));

        self::assertCount(2, $this->events);
        foreach ($this->events as [$event]) {
            self::assertInstanceOf(LoginThrottledEvent::class, $event);
            self::assertSame(LoginThrottledEvent::SCOPE_IP, $event->scope);
            self::assertSame(2, $event->limit);
        }
        self::assertTrue($this->events[0][0]->lockoutStarted);
        self::assertFalse($this->events[1][0]->lockoutStarted);
        self::assertSame('c', $this->events[1][0]->username);
    }

    public function testWithoutUsernameScope(): void
    {
        $limiter = $this->limiter(maxAttempts: 3, maxAttemptsWithoutUsername: 1);

        self::assertFalse($limiter->consume($this->request(null))->isAccepted());
        self::assertFalse($limiter->consume($this->request(null))->isAccepted());

        self::assertCount(2, $this->events);
        [[$first], [$second]] = $this->events;
        self::assertInstanceOf(LoginThrottledEvent::class, $first);
        self::assertInstanceOf(LoginThrottledEvent::class, $second);
        self::assertNull($first->username);
        self::assertSame(LoginThrottledEvent::SCOPE_WITHOUT_USERNAME, $first->scope);
        self::assertSame(1, $first->limit);
        self::assertTrue($first->lockoutStarted);
        self::assertSame(LoginThrottledEvent::SCOPE_WITHOUT_USERNAME, $second->scope);
        self::assertFalse($second->lockoutStarted);
    }

    public function testResetDispatchesClearedOnlyWithAUsername(): void
    {
        $limiter = $this->limiter(maxAttempts: 5);
        $limiter->consume($this->request('bob'));
        $limiter->consume($this->request(null));

        $limiter->reset($this->request(null));
        self::assertSame([], $this->events, 'the bucket without a username is never cleared (KF-L-M1)');

        $limiter->reset($this->request('Bob'));

        self::assertCount(1, $this->events);
        $event = $this->events[0][0];
        self::assertInstanceOf(LoginAttemptsClearedEvent::class, $event);
        self::assertSame('10.0.0.1', $event->ipAddress);
        self::assertSame('bob', $event->username);
        self::assertSame(1, $event->cleared);
        self::assertSame('main', $event->firewallName);
    }

    public function testFirewallNameIsNullOutsideAFirewallAndKeptWhenNotPrefixed(): void
    {
        $limiter = $this->limiter(maxAttempts: 1);

        $request = $this->request('carol');
        $request->attributes->remove('_firewall_context');
        $limiter->consume($request);

        $custom = $this->request('dave');
        $custom->attributes->set('_firewall_context', 'admin');
        $limiter->consume($custom);

        self::assertNull($this->events[0][0]->firewallName);
        self::assertSame('admin', $this->events[1][0]->firewallName);
    }

    public function testNoDispatcherNoEventsSameDecisions(): void
    {
        $limiter = new DatabaseRateLimiter($this->store, 1, 600);

        self::assertFalse($limiter->consume($this->request('erin'))->isAccepted());
        $limiter->reset($this->request('erin'));

        self::assertSame([], $this->events);
    }

    public function testFactoryPassesTheDispatcher(): void
    {
        $limiter = (new DatabaseRateLimiterFactory($this->store, 1, 600, eventDispatcher: $this->dispatcher))->create();

        $limiter->consume($this->request('frank'));

        self::assertCount(1, $this->events);
    }

    /**
     * Worker mode (kernel kept alive): one limiter instance serves two visitors; each event carries only its own
     * request's data.
     */
    public function testOneInstanceKeepsNoStateBetweenVisitors(): void
    {
        $limiter = $this->limiter(maxAttempts: 1);

        $limiter->consume($this->request('gina'));
        $other = Request::create('/login', 'POST', ['_username' => 'hank'], server: ['REMOTE_ADDR' => '10.0.0.2']);
        $limiter->consume($other);

        self::assertSame(['10.0.0.1', 'gina'], [$this->events[0][0]->ipAddress, $this->events[0][0]->username]);
        self::assertSame(['10.0.0.2', 'hank'], [$this->events[1][0]->ipAddress, $this->events[1][0]->username]);
        self::assertNull($this->events[1][0]->firewallName);
    }

    private function limiter(int $maxAttempts, ?int $maxAttemptsPerIp = null, ?int $maxAttemptsWithoutUsername = null): DatabaseRateLimiter
    {
        $clock = new class($this->store) implements ClockInterface {
            public function __construct(private readonly EntityFreeLoginAttemptStore $store)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->store->now;
            }
        };

        return new DatabaseRateLimiter($this->store, $maxAttempts, 600, $maxAttemptsPerIp, $clock, false, $maxAttemptsWithoutUsername, $this->dispatcher);
    }

    private function request(?string $username): Request
    {
        $request = Request::create('/login', 'POST', null === $username ? [] : ['_username' => $username], server: ['REMOTE_ADDR' => '10.0.0.1']);
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');

        return $request;
    }
}
