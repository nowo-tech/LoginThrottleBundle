<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptRecorderInterface;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;

/**
 * The same store, also implementing the entity-free write port (3.12).
 */
final class InMemoryLoginAttemptRecorderStore extends InMemoryLoginAttemptStore implements LoginAttemptRecorderInterface
{
    public int $recordAttemptCalls = 0;

    public int $recordAttemptViewCalls = 0;

    public function recordAttempt(string $ipAddress, ?string $username): LoginAttempt
    {
        ++$this->recordAttemptCalls;

        return parent::recordAttempt($ipAddress, $username);
    }

    public function recordAttemptView(string $ipAddress, ?string $username): LoginAttemptView
    {
        ++$this->recordAttemptViewCalls;
        $this->rows[] = ['ip' => $ipAddress, 'username' => $username, 'at' => $this->now];

        return new LoginAttemptView(null, $ipAddress, $username, $this->now);
    }
}
