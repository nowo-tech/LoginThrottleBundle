<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Repository;

use Nowo\LoginThrottleBundle\Entity\LoginAttempt;
use Nowo\LoginThrottleBundle\Repository\LoginAttemptView;
use PHPUnit\Framework\TestCase;

final class LoginAttemptViewTest extends TestCase
{
    public function testFromEntity(): void
    {
        $attempt = (new LoginAttempt('10.0.0.1', 'alice'))->setBlocked(true);
        $view = LoginAttemptView::fromEntity($attempt);

        self::assertNull($view->id);
        self::assertSame('10.0.0.1', $view->ipAddress);
        self::assertSame('alice', $view->username);
        self::assertSame($attempt->getCreatedAt(), $view->createdAt);
        self::assertTrue($view->blocked);
    }
}
