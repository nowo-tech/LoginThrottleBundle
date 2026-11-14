<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Testing;

use Nowo\LoginThrottleBundle\Repository\LoginAttemptStoreInterface;
use Nowo\LoginThrottleBundle\Testing\LoginAttemptStoreContractTestCase;
use Nowo\LoginThrottleBundle\Tests\Support\EntityFreeLoginAttemptStore;

/**
 * The in-memory DDD-host test store follows the store contract (audit PN L-3).
 */
final class EntityFreeStoreContractTest extends LoginAttemptStoreContractTestCase
{
    private EntityFreeLoginAttemptStore $store;

    protected function createStore(): LoginAttemptStoreInterface
    {
        return $this->store = new EntityFreeLoginAttemptStore();
    }

    protected function advance(int $seconds): void
    {
        $this->store->advance($seconds);
    }
}
