<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\EventSubscriber;

use Nowo\LoginThrottleBundle\Transaction\LoginThrottleStorageFailure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6, audit TJ-2): the part of the conflict answer that must happen whoever renders it. It sets
 * no response and runs above every renderer (priority 128: the bundle's own subscriber, a host listener, the
 * `map_transient_failures` responder of nowo-tech/transaction-doctrine at 64), in both `conflict_response` modes.
 *
 * - **Ends the login** when the failure comes from the reset after a right password: Symfony already stored the token, and
 *   the context listener would save it in the session. Token cleared, session invalidated, remember-me cookie dropped:
 *   whoever answers, the next request is anonymous.
 * - **Removes the commit mark of transaction-doctrine 1.6** from the real request, so any responder (the bundle's, the
 *   shared one) answers `CONFLICT_001` whatever the host committed before. A host listener that commits only for existing
 *   accounts would otherwise turn the code into an account-existence oracle (owner-approved exemption of the
 *   request-level detection, 2026-11-18; see docs/CONCURRENCY.md).
 *
 * Acts only on a failure the limiter marked on the request. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LoginConflictListener implements EventSubscriberInterface
{
    /** CommitMark::REQUEST_ATTRIBUTE of transaction-doctrine 1.6 (a literal: 1.5 has no such class). */
    private const COMMIT_MARK_ATTRIBUTE = '_nowo_transaction_doctrine_commit_mark';

    /** Symfony's attribute holding the remember-me cookie to send with the response. */
    private const REMEMBER_ME_ATTRIBUTE = '_security_remember_me_cookie';

    public function __construct(private ?TokenStorageInterface $tokenStorage = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 128]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $mark = $request->attributes->get(LoginThrottleStorageFailure::REQUEST_ATTRIBUTE);
        if (LoginThrottleStorageFailure::DURING_ATTEMPT !== $mark && LoginThrottleStorageFailure::DURING_SUCCESS !== $mark) {
            return;
        }
        $request->attributes->remove(self::COMMIT_MARK_ATTRIBUTE);
        if (LoginThrottleStorageFailure::DURING_SUCCESS !== $mark) {
            return;
        }
        $this->tokenStorage?->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }
        $request->attributes->remove(self::REMEMBER_ME_ATTRIBUTE);
    }
}
