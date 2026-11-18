<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\DependencyInjection;

use Nowo\LoginThrottleBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\LoginThrottleBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 3.16 (REQ-RUNTIME-003 rule 6): which policy and responder answer a lock conflict, and how the host takes it over.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictResponsePassTest extends TestCase
{
    public function testWithoutTheSubscriberThereIsNothingToWire(): void
    {
        $container = new ContainerBuilder();

        (new ConflictResponsePass())->process($container);

        $this->assertFalse($container->has(ConflictResponsePass::OWN_POLICY));
    }

    public function testDefaultsGive409WithTheBundlesOwnPolicyWhenTheSharedBundleIsAbsent(): void
    {
        $container = $this->container(null);

        (new ConflictResponsePass())->process($container);

        $policy = $container->getDefinition(ConflictResponsePass::OWN_POLICY);
        $this->assertSame([ConflictResponsePolicy::STATUS_CONFLICT], $policy->getArguments());
        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        $this->assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $subscriber->getArgument('$policy'));
        $responder = $subscriber->getArgument('$responder');
        $this->assertInstanceOf(Definition::class, $responder);
        $this->assertSame(ConcurrencyConflictResponder::class, $responder->getClass());
        $this->assertSame([], $responder->getArgument(2), 'no formatters without the shared bundle');
        $this->assertNull($responder->getArgument(3));
    }

    public function testASetStatusUsesTheOwnPolicyEvenWithTheSharedBundle(): void
    {
        $container = $this->container(['mode' => 'bundle', 'status' => 503]);
        $container->setDefinition(ConflictResponsePass::SHARED_POLICY, new Definition(ConflictResponsePolicy::class));
        $container->setDefinition(ConflictResponsePass::SHARED_RESPONDER, new Definition(ConcurrencyConflictResponder::class));

        (new ConflictResponsePass())->process($container);

        $this->assertSame([503], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
        $responder = $container->getDefinition(StorageFailureSubscriber::class)->getArgument('$responder');
        $this->assertInstanceOf(Definition::class, $responder, 'its own responder, built on the own policy');
        $this->assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $responder->getArgument(0));
    }

    public function testTheSharedPolicyAndResponderAreUsedWhenNoStatusIsSet(): void
    {
        $container = $this->container(['mode' => 'bundle', 'status' => null]);
        $container->setDefinition(ConflictResponsePass::SHARED_POLICY, new Definition(ConflictResponsePolicy::class));
        $container->setDefinition(ConflictResponsePass::SHARED_RESPONDER, new Definition(ConcurrencyConflictResponder::class));

        (new ConflictResponsePass())->process($container);

        $subscriber = $container->getDefinition(StorageFailureSubscriber::class);
        $this->assertEquals(new Reference(ConflictResponsePass::SHARED_POLICY), $subscriber->getArgument('$policy'));
        $this->assertEquals(new Reference(ConflictResponsePass::SHARED_RESPONDER), $subscriber->getArgument('$responder'));
        $this->assertFalse($container->hasDefinition(ConflictResponsePass::OWN_POLICY));
    }

    public function testTheSharedPolicyWithoutItsResponderBuildsOneOnTheSharedPolicy(): void
    {
        $container = $this->container(['mode' => 'bundle', 'status' => null]);
        $container->setDefinition(ConflictResponsePass::SHARED_POLICY, new Definition(ConflictResponsePolicy::class));

        (new ConflictResponsePass())->process($container);

        $responder = $container->getDefinition(StorageFailureSubscriber::class)->getArgument('$responder');
        $this->assertInstanceOf(Definition::class, $responder);
        $this->assertEquals(new Reference(ConflictResponsePass::SHARED_POLICY), $responder->getArgument(0));
        $this->assertNotSame([], $responder->getArgument(2), 'the formatters of the application');
    }

    public function testHostModeRemovesTheSubscriber(): void
    {
        $container = $this->container(['mode' => 'host', 'status' => null]);

        (new ConflictResponsePass())->process($container);

        $this->assertFalse($container->hasDefinition(StorageFailureSubscriber::class), 'the failure reaches the host untouched');
    }

    /**
     * @param array{mode: string, status: int|null}|null $config
     */
    private function container(?array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(StorageFailureSubscriber::class, StorageFailureSubscriber::class);
        if (null !== $config) {
            $container->setParameter(ConflictResponsePass::PARAMETER, $config);
        }

        return $container;
    }
}
