<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\DependencyInjection\Compiler;

use Nowo\LoginThrottleBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6): wires the conflict answer of {@see StorageFailureSubscriber} once every extension is
 * loaded.
 *
 * - `nowo_login_throttle.conflict_response.mode: host`: the subscriber is removed, the failure reaches the host as before
 *   3.16 (it maps `TransientStorageFailure` itself).
 * - `mode: bundle` (default), `status` left out: with NowoTransactionDoctrineBundle registered, its policy and responder
 *   (template, translations, JSON error key, formatters); without it, 409 + `Retry-After: 1` and the plain answer.
 * - `status: 409 | 503`: the bundle's own policy with that status (`Retry-After: 1`).
 */
final class ConflictResponsePass implements CompilerPassInterface
{
    /** Parameter set by the extension: `conflict_response` (mode, status). */
    public const PARAMETER = 'nowo_login_throttle.conflict_response';

    public const MODE_BUNDLE = 'bundle';
    public const MODE_HOST = 'host';

    /** Services of nowo-tech/transaction-doctrine's Symfony bundle. */
    public const SHARED_POLICY = 'nowo_transaction_doctrine.conflict_response_policy';
    public const SHARED_RESPONDER = 'nowo_transaction_doctrine.conflict_responder';

    /** The bundle's own policy, when `status` is set or the shared bundle is not registered. */
    public const OWN_POLICY = 'nowo_login_throttle.conflict_response_policy';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(StorageFailureSubscriber::class)) {
            return;
        }
        /** @var array{mode: string, status: int|null} $config */
        $config = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : ['mode' => self::MODE_BUNDLE, 'status' => null];
        if (self::MODE_HOST === $config['mode']) {
            $container->removeDefinition(StorageFailureSubscriber::class);

            return;
        }
        $status = $config['status'];
        $shared = $container->has(self::SHARED_POLICY);

        if (\is_int($status) || !$shared) {
            $container->setDefinition(self::OWN_POLICY, new Definition(ConflictResponsePolicy::class, [$status ?? ConflictResponsePolicy::STATUS_CONFLICT]));
            $policy = new Reference(self::OWN_POLICY);
        } else {
            $policy = new Reference(self::SHARED_POLICY);
        }

        if (!\is_int($status) && $container->has(self::SHARED_RESPONDER)) {
            $responder = new Reference(self::SHARED_RESPONDER);
        } else {
            $responder = new Definition(ConcurrencyConflictResponder::class, [
                $policy,
                [],
                $shared ? new TaggedIteratorArgument(ConflictResponseFormatterInterface::TAG) : [],
                $shared ? new Reference('twig', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
                $shared ? new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
        }

        $container->getDefinition(StorageFailureSubscriber::class)
            ->setArgument('$policy', $policy)
            ->setArgument('$responder', $responder);
    }
}
