<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Support;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Host listeners of the conflict kernel tests (3.16): one renders the conflict itself (kernel.exception, priority 0), the
 * other commits something only for an existing account (kernel.request), as a "last seen" write would.
 */
final class HostConflictListeners
{
    public function render(ExceptionEvent $event): void
    {
        $event->setResponse(new Response('host answer', 503));
    }

    public function commitForExistingAccounts(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('alice' !== $request->request->get('_username')) {
            return;
        }
        $ledger = new CommitLedger();
        $mark = new CommitMark($ledger);
        $ledger->record();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, $mark);
    }
}
