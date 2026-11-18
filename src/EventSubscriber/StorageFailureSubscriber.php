<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\EventSubscriber;

use Nowo\LoginThrottleBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\LoginThrottleBundle\Transaction\LoginThrottleStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6): answers the {@see LoginThrottleStorageFailure} of a login the bundle guards instead of a
 * 500: **409 + `Retry-After` + `CONFLICT_001`** (the status is configurable to 503, see {@see ConflictResponsePass}).
 *
 * - **Fails closed.** The failure was thrown by the limiter before the password check (or by the reset after it), so the
 *   login did not succeed: the answer is never a 2xx or a redirect, the attempt is a refusal, never an allow.
 * - **Not an oracle.** The answer is built from a fresh {@see LoginThrottleStorageFailure}: the status, the headers and the
 *   text are the same whether the account exists, is locked, or the password is right (nothing of the request, the
 *   username or the storage error reaches the body, the logs of the responder carry only the route and the method).
 * - **Only logins.** Only a failure the limiter marked on the request (raised while authenticating) is answered. When it
 *   comes from the reset after a right password, {@see LoginConflictListener} has already ended the login.
 * - **Only the guarded firewalls.** A failure raised on a route outside a firewall configured in `nowo_login_throttle`
 *   (a command's request, an unguarded firewall) is left to the host. A response already set by a host listener wins.
 *
 * Priority -64: after Symfony logged the original exception, before it renders the response. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class StorageFailureSubscriber implements EventSubscriberInterface
{
    /** The platform's conflict-code header (the same name as ConflictResponsePolicy::CODE_HEADER of transaction-doctrine 1.6). */
    public const CODE_HEADER = 'X-Conflict-Code';

    private ConcurrencyConflictResponder $responder;

    /**
     * @param Security|null                     $security  Resolves the firewall of the request (null without SecurityBundle: nothing is answered)
     * @param list<string>                      $firewalls Names of the firewalls the bundle guards
     * @param ConflictResponsePolicy|null       $policy    Status (409 or 503) and Retry-After; null = 409 + Retry-After: 1
     * @param ConcurrencyConflictResponder|null $responder Builds the answer; null = a plain one with $policy
     */
    public function __construct(
        private ?Security $security = null,
        private array $firewalls = [],
        ?ConflictResponsePolicy $policy = null,
        ?ConcurrencyConflictResponder $responder = null,
    ) {
        $this->responder = $responder ?? new ConcurrencyConflictResponder($policy ?? new ConflictResponsePolicy());
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        if (!$failure instanceof LoginThrottleStorageFailure || $event->hasResponse()) {
            return;
        }
        $request = $event->getRequest();
        // without the security service there is no firewall, so no login of the bundle
        $firewall = $this->security?->getFirewallConfig($request)?->getName();
        if (null === $firewall || !\in_array($firewall, $this->firewalls, true)) {
            return;
        }

        $mark = $request->attributes->get(LoginThrottleStorageFailure::REQUEST_ATTRIBUTE);
        if (LoginThrottleStorageFailure::DURING_ATTEMPT !== $mark && LoginThrottleStorageFailure::DURING_SUCCESS !== $mark) {
            // not raised while authenticating (host code calling the store through its public API): the host's answer
            return;
        }
        // The login of a right password is ended, and the commit mark removed, by LoginConflictListener (priority 128, in
        // both modes), so a renderer of the host or of transaction-doctrine cannot skip it.
        // a fresh failure: nothing of the original (its previous chain, its message) can reach the answer
        $response = $this->responder->respond($request, new LoginThrottleStorageFailure());
        // @igor-ignore - Mutates the response just built for this request; not worker singleton state
        $response->headers->set(self::CODE_HEADER, ConflictCode::RetrySafe->value);
        $event->setResponse($response);
    }
}
