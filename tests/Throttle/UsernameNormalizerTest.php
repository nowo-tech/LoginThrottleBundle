<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Throttle;

use Nowo\LoginThrottleBundle\Throttle\UsernameNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class UsernameNormalizerTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('admin', UsernameNormalizer::normalize('  Admin '));
        self::assertSame('ñandú@example.com', UsernameNormalizer::normalize('ÑANDÚ@example.com'));
        self::assertNull(UsernameNormalizer::normalize('   '));
        self::assertNull(UsernameNormalizer::normalize(null));
        self::assertNull(UsernameNormalizer::normalize(['admin']));
    }

    public function testPostFieldsComeFirstAndNestedBagBeforeFlatFields(): void
    {
        $request = Request::create('/login', 'POST', ['login_form' => ['email' => 'Nested'], '_username' => 'flat']);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'passport');

        self::assertSame('nested', UsernameNormalizer::fromRequest($request, true));
        self::assertSame('flat', UsernameNormalizer::fromRequest(Request::create('/login', 'POST', ['username' => ' ', 'email' => 'Flat'])));
    }

    public function testPassportIdentifierOnlyWhenEnabled(): void
    {
        $request = Request::create('/api/login', 'POST');
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, ' Json@Example.com');

        self::assertNull(UsernameNormalizer::fromRequest($request));
        self::assertSame('json@example.com', UsernameNormalizer::fromRequest($request, true));
        self::assertNull(UsernameNormalizer::fromRequest(Request::create('/api/login', 'POST'), true));
    }
}
