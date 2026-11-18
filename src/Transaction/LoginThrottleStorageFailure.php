<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Transaction;

use Nowo\LoginThrottleBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6): the attempt store of the login throttle failed transiently (the lock of the IP address
 * was not granted in time, a deadlock, a lock wait timeout, a serialization failure) and nothing of the unit is kept.
 *
 * It is a {@see TransientStorageFailure}, so code that already catches the contract's exception keeps working. Only
 * the bundle raises it, which lets {@see StorageFailureSubscriber} answer it without taking over the failures of the
 * host. The message is generic and carries nothing of the visitor (no username, no IP address, no account state): the
 * storage error stays in the previous exception, for the logs only.
 *
 * A login attempt never commits anything the client could repeat by mistake, so the conflict code is always
 * {@see ConflictCode::RetrySafe} (`CONFLICT_001`, with `Retry-After`).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LoginThrottleStorageFailure extends TransientStorageFailure
{
    /**
     * Request attribute set by the limiter when the failure comes from the authentication of a login. Only such a failure
     * is answered by {@see StorageFailureSubscriber}: host code on a guarded firewall that raises the exception through the
     * public API keeps the host's rendering.
     */
    public const REQUEST_ATTRIBUTE = '_nowo_login_throttle_conflict';

    /** The failure came from deciding an attempt, before the password check. */
    public const DURING_ATTEMPT = 'attempt';

    /** The failure came from the reset after a successful login: the login must not complete. */
    public const DURING_SUCCESS = 'success';

    /** The message of every answer: the same whatever the account. */
    public const MESSAGE = 'The login could not be checked right now. Retry later.';

    public function __construct(?int $retryAfterSeconds = AttemptLockRunner::RETRY_AFTER_SECONDS, ?\Throwable $previous = null)
    {
        parent::__construct($retryAfterSeconds, self::MESSAGE, $previous);
    }

    /**
     * The same failure under the bundle's type; the storage error stays in the previous chain.
     */
    public static function from(\Throwable $failure): self
    {
        $retryAfter = $failure instanceof TransientStorageFailure ? $failure->retryAfterSeconds() : AttemptLockRunner::RETRY_AFTER_SECONDS;

        return new self($retryAfter, $failure);
    }

    /**
     * Always {@see ConflictCode::RetrySafe}: the attempt was not recorded and no answer of the login was committed.
     */
    public function conflictCode(): ConflictCode
    {
        return ConflictCode::RetrySafe;
    }
}
