<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Transaction;

use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\LoginThrottleBundle\Transaction\AttemptLockRunner;
use Nowo\LoginThrottleBundle\Transaction\LoginThrottleStorageFailure;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\TestCase;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6): the failure the bundle raises, its code and what it keeps out of its message.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LoginThrottleStorageFailureTest extends TestCase
{
    public function testItIsATransientStorageFailureWithAGenericMessageAndOneSecondOfRetryAfter(): void
    {
        $failure = new LoginThrottleStorageFailure();

        $this->assertInstanceOf(TransientStorageFailure::class, $failure);
        $this->assertSame(LoginThrottleStorageFailure::MESSAGE, $failure->getMessage());
        $this->assertSame(AttemptLockRunner::RETRY_AFTER_SECONDS, $failure->retryAfterSeconds());
        $this->assertSame(ConflictCode::RetrySafe, $failure->conflictCode());
    }

    public function testFromKeepsTheStorageErrorAsPreviousAndTheHintOfAContractFailure(): void
    {
        $storage = new TransientStorageFailure(7, 'SQL: select * from login_attempts where username = \'alice\'');

        $failure = LoginThrottleStorageFailure::from($storage);

        $this->assertSame($storage, $failure->getPrevious());
        $this->assertSame(7, $failure->retryAfterSeconds());
        $this->assertStringNotContainsString('alice', $failure->getMessage(), 'the message carries nothing of the storage error');
    }

    public function testFromAnyOtherThrowableUsesTheDefaultHint(): void
    {
        $failure = LoginThrottleStorageFailure::from(new \RuntimeException('deadlock'));

        $this->assertSame(AttemptLockRunner::RETRY_AFTER_SECONDS, $failure->retryAfterSeconds());
    }

    public function testTheCodeIsRetrySafeWhateverTheChainHolds(): void
    {
        $deadlock = new DeadlockException($this->createStub(Exception::class), null);

        $this->assertSame(ConflictCode::RetrySafe, LoginThrottleStorageFailure::from($deadlock)->conflictCode());
    }
}
