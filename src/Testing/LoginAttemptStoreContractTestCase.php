<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Testing;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Conformance tests for a {@see LoginAttemptStoreInterface} implementation (3.14).
 *
 * Extend it in your test suite (PHPUnit is a dev dependency of your project, not of this bundle) to check your own
 * store against the contract the database limiter relies on, in particular the binding {@see
 * LoginAttemptStoreInterface::clearAttempts()} rule: a successful login deletes only the newest matching row and the
 * older ones keep counting for the IP. The bundle runs it against its own Doctrine repository and its in-memory test
 * store.
 *
 * ```php
 * final class RedisLoginAttemptStoreTest extends LoginAttemptStoreContractTestCase
 * {
 *     protected function createStore(): LoginAttemptStoreInterface { ... }       // a fresh, empty store
 *     protected function advance(int $seconds): void { $this->clock->sleep($seconds); } // the store's clock
 * }
 * ```
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
abstract class LoginAttemptStoreContractTestCase extends TestCase
{
    /**
     * A fresh, empty store.
     */
    abstract protected function createStore(): LoginAttemptStoreInterface;

    /**
     * Moves the clock the store reads forward.
     */
    abstract protected function advance(int $seconds): void;

    public function testRecordsAndCounts(): void
    {
        $store = $this->createStore();
        $store->recordAttemptView('10.0.0.1', 'alice');
        $store->recordAttemptView('10.0.0.1', 'alice');
        $store->recordAttemptView('10.0.0.1', 'bob');
        $store->recordAttemptView('10.0.0.1', null);
        $store->recordAttemptView('10.0.0.2', 'alice');

        self::assertSame(2, $store->countAttempts('10.0.0.1', 'alice', 600));
        self::assertSame(4, $store->countAttempts('10.0.0.1', null, 600), 'null: every attempt of the IP');
        self::assertSame(4, $store->countAttemptsByIp('10.0.0.1', 600));
        self::assertSame(3, $store->countAttemptsByUsername('alice', 600));
        self::assertSame(1, $store->countAttemptsWithoutUsername('10.0.0.1', 600));
        self::assertTrue($store->isBlocked('10.0.0.1', 'alice', 2, 600));
        self::assertFalse($store->isBlocked('10.0.0.1', 'alice', 3, 600));
        self::assertFalse($store->isBlocked('10.0.0.3', 'alice', 1, 600));
    }

    public function testRecordAttemptViewReturnsTheStoredRow(): void
    {
        $store = $this->createStore();

        $view = $store->recordAttemptView('10.0.0.1', 'alice');
        $ipOnly = $store->recordAttemptView('10.0.0.1', null);

        self::assertSame('10.0.0.1', $view->ipAddress);
        self::assertSame('alice', $view->username);
        self::assertNull($ipOnly->username);
    }

    /**
     * The 3.11.0 rule: clearing an IP + username deletes only the newest row (the one the successful login recorded
     * itself) and keeps the older failures counting for the IP without a username. A store that deletes them all
     * lets a successful login on an attacker's own account refill the per-IP cap.
     */
    public function testClearDeletesTheNewestRowAndKeepsTheOlderOnesCountingForTheIp(): void
    {
        $store = $this->createStore();
        $store->recordAttemptView('10.0.0.1', 'alice');
        $store->recordAttemptView('10.0.0.1', 'alice');
        $store->recordAttemptView('10.0.0.1', 'alice');
        $store->recordAttemptView('10.0.0.1', 'bob');
        $store->recordAttemptView('10.0.0.2', 'alice');

        self::assertSame(3, $store->clearAttempts('10.0.0.1', 'alice'));

        self::assertSame(0, $store->countAttempts('10.0.0.1', 'alice', 600), 'the username bucket starts afresh');
        self::assertSame(3, $store->countAttemptsByIp('10.0.0.1', 600), 'the two older failures still count for the IP');
        self::assertSame(2, $store->countAttemptsWithoutUsername('10.0.0.1', 600));
        self::assertSame(1, $store->countAttempts('10.0.0.1', 'bob', 600));
        self::assertSame(1, $store->countAttempts('10.0.0.2', 'alice', 600), 'other IPs are untouched');
    }

    public function testClearWithoutUsernameTouchesOnlyRowsWithoutUsername(): void
    {
        $store = $this->createStore();
        $store->recordAttemptView('10.0.0.1', null);
        $store->recordAttemptView('10.0.0.1', null);
        $store->recordAttemptView('10.0.0.1', 'alice');

        self::assertSame(2, $store->clearAttempts('10.0.0.1', ''));

        self::assertSame(1, $store->countAttemptsByIp('10.0.0.1', 600));
        self::assertSame(1, $store->countAttempts('10.0.0.1', 'alice', 600));
    }

    public function testClearMatchesTheUsernameExactly(): void
    {
        $store = $this->createStore();
        $store->recordAttemptView('10.0.0.1', 'alice');

        self::assertSame(0, $store->clearAttempts('10.0.0.1', 'bob'));
        self::assertSame(0, $store->clearAttempts('10.0.0.1', 'alice '));
        self::assertSame(1, $store->countAttempts('10.0.0.1', 'alice', 600));
    }

    public function testViewsAreNewestFirstAndTheOldestTimeIsReported(): void
    {
        $store = $this->createStore();
        $store->recordAttemptView('10.0.0.1', 'alice');
        $store->recordAttemptView('10.0.0.1', null);
        $this->advance(10);
        $store->recordAttemptView('10.0.0.1', 'alice');
        $store->recordAttemptView('10.0.0.1', null);

        $views = $store->getAttemptViews('10.0.0.1', 'alice', 600);
        self::assertCount(2, $views);
        self::assertGreaterThan($views[1]->createdAt->getTimestamp(), $views[0]->createdAt->getTimestamp());

        $oldest = $store->findOldestAttemptAt('10.0.0.1', 'alice', 600);
        self::assertNotNull($oldest);
        self::assertSame($views[1]->createdAt->getTimestamp(), $oldest->getTimestamp());

        $oldestWithoutUsername = $store->findOldestAttemptWithoutUsernameAt('10.0.0.1', 600);
        self::assertNotNull($oldestWithoutUsername);
        self::assertSame($oldest->getTimestamp(), $oldestWithoutUsername->getTimestamp());

        self::assertNull($store->findOldestAttemptAt('10.0.0.9', null, 600));
        self::assertNull($store->findOldestAttemptWithoutUsernameAt('10.0.0.9', 600));
    }

    public function testAttemptsLeaveTheWindowAndAreCleanedUp(): void
    {
        $store = $this->createStore();
        $store->recordAttemptView('10.0.0.1', 'alice');
        $this->advance(700);
        $store->recordAttemptView('10.0.0.1', 'alice');

        self::assertSame(1, $store->countAttempts('10.0.0.1', 'alice', 600));
        self::assertSame(1, $store->countAttemptsByIp('10.0.0.1', 600));
        self::assertSame(1, $store->countOlderThan(600));

        self::assertSame(1, $store->cleanup(600));

        self::assertSame(0, $store->countOlderThan(600));
        self::assertSame(1, $store->countAttemptsByIp('10.0.0.1', 3600));
    }
}
