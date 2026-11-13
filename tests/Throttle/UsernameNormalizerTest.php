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

    /**
     * REQ-SEC-006 item 1 (3.11.0): unusable usernames are refused, never cleaned into another user's bucket.
     */
    public function testUnusableUsernamesAreRefused(): void
    {
        foreach (["bob\n", "bob\t", "\tbob", "bob\r", "bob\0", "bob\u{00A0}", "\u{00A0}bob", "bob\u{200B}", "b\u{200B}ob", "\u{FEFF}bob", "bob\u{200E}", "bob\u{202E}", "bob\u{2028}", "bob\u{3000}", "bob\x80", "\xC3\x28"] as $value) {
            self::assertNull(UsernameNormalizer::normalize($value), json_encode($value) ?: bin2hex($value));
        }
        self::assertNull(UsernameNormalizer::normalize(str_repeat('a', UsernameNormalizer::MAX_LENGTH + 1)));
        self::assertSame(str_repeat('a', UsernameNormalizer::MAX_LENGTH), UsernameNormalizer::normalize(str_repeat('A', UsernameNormalizer::MAX_LENGTH)));
        // the documented normalisation stays: edge ASCII spaces trimmed, lower-cased; accents are kept (distinct ids)
        self::assertSame('bob@example.com', UsernameNormalizer::normalize(' BOB@example.com  '));
        self::assertSame('bób@example.com', UsernameNormalizer::normalize('Bób@example.com'));
        self::assertSame('bob smith', UsernameNormalizer::normalize('Bob Smith'));
    }

    public function testPostFieldsComeFirstAndNestedBagBeforeFlatFields(): void
    {
        $request = Request::create('/login', 'POST', ['login_form' => ['email' => 'Nested'], '_username' => 'flat']);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'passport');

        self::assertSame('nested', UsernameNormalizer::fromRequest($request, true));
        self::assertSame('flat', UsernameNormalizer::fromRequest(Request::create('/login', 'POST', ['username' => ' ', 'email' => 'Flat'])));
    }

    /**
     * REQ-SEC-006: an unusable first field never falls through to a later field (another user's bucket).
     */
    public function testAnUnusableFieldDoesNotFallThroughToALaterOne(): void
    {
        $request = Request::create('/login', 'POST', ['_username' => "attacker\t", 'email' => 'victim@example.com']);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'victim@example.com');
        self::assertNull(UsernameNormalizer::fromRequest($request, true));

        $nested = Request::create('/login', 'POST', ['login_form' => ['_username' => "attacker\u{200B}"], '_username' => 'victim@example.com']);
        self::assertNull(UsernameNormalizer::fromRequest($nested));

        // a later field is not read once an earlier one decides (an array there would make InputBag::get() throw)
        self::assertSame('alice', UsernameNormalizer::fromRequest(Request::create('/login', 'POST', ['_username' => 'Alice', 'email' => ['extra']])));

        // blank fields still fall through, as before
        self::assertSame('victim@example.com', UsernameNormalizer::fromRequest(Request::create('/login', 'POST', ['_username' => "\t ", 'email' => 'Victim@example.com'])));
    }

    public function testSkeletonFoldsCaseAccentsAndWidth(): void
    {
        $skeleton = UsernameNormalizer::skeleton('bob@example.com');
        foreach (['BOB@example.com', 'bób@example.com', "bo\u{0301}b@example.com", 'ｂｏｂ@example.com', 'Bõb@EXAMPLE.com'] as $variant) {
            self::assertSame($skeleton, UsernameNormalizer::skeleton($variant), $variant);
        }
        self::assertSame('strasse', UsernameNormalizer::skeleton('Straße'));
        self::assertNotSame($skeleton, UsernameNormalizer::skeleton('bob@example.com '));
        self::assertNotSame($skeleton, UsernameNormalizer::skeleton("bob@example.com\u{200B}"));
        self::assertSame("bob\xFF", UsernameNormalizer::skeleton("bob\xFF"), 'invalid UTF-8 is compared as stored');
    }

    /**
     * Audit F-LT-1 (3.11.0): the limiter keys on the identifier Symfony authenticates (LAST_USERNAME), not on another
     * POST field.
     */
    public function testForLimiterPrefersTheAuthenticatedIdentifier(): void
    {
        $confused = Request::create('/login', 'POST', ['login_form' => ['_username' => 'victim@example.com'], '_username' => 'Attacker@example.com']);
        self::assertSame('victim@example.com', UsernameNormalizer::forLimiter($confused), 'without the attribute the POST fields decide');
        $confused->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'Attacker@example.com');
        self::assertSame('attacker@example.com', UsernameNormalizer::forLimiter($confused));

        $unusable = Request::create('/login', 'POST', ['_username' => "attacker\t"]);
        $unusable->attributes->set(SecurityRequestAttributes::LAST_USERNAME, "attacker\t");
        self::assertNull(UsernameNormalizer::forLimiter($unusable, true));

        // json_login (no username field): IP-only unless username_from_passport
        $json = Request::create('/api/login', 'POST');
        $json->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'Json@Example.com');
        self::assertNull(UsernameNormalizer::forLimiter($json));
        self::assertSame('json@example.com', UsernameNormalizer::forLimiter($json, true));
        self::assertNull(UsernameNormalizer::forLimiter(Request::create('/api/login', 'POST')));
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
