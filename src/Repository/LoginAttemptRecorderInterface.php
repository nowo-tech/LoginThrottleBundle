<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Repository;

/**
 * Entity-free writes of the login attempt store (3.12), the twin of
 * {@see LoginAttemptRepositoryInterface::recordAttempt()} (REQ-ARCH-001 rule 1).
 *
 * Implemented by {@see LoginAttemptRepository}. A host store that implements {@see LoginAttemptRepositoryInterface}
 * can implement this one too; the database limiter then records through it instead of
 * {@see LoginAttemptRepositoryInterface::recordAttempt()} (which returns the Doctrine entity), so no Doctrine type
 * crosses the port on the write path either. Kept separate from {@see LoginAttemptViewRepositoryInterface} so
 * existing implementations of both stay valid.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
interface LoginAttemptRecorderInterface
{
    /**
     * Records a failed login attempt and returns its read-only view.
     *
     * Same contract as {@see LoginAttemptRepositoryInterface::recordAttempt()}: one stored attempt per call, the
     * bundle's own connection, never a flush of the host's EntityManager.
     *
     * @param string      $ipAddress IP address
     * @param string|null $username  Username (null for the IP-only bucket)
     *
     * @return LoginAttemptView The recorded attempt (its id set when the store assigns one)
     */
    public function recordAttemptView(string $ipAddress, ?string $username): LoginAttemptView;
}
