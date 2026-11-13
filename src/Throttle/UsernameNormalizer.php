<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Throttle;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Extracts and normalises the submitted username the database limiter keys its attempts on (3.9).
 *
 * One implementation for the limiter, the status provider and the info service, so they always count the same bucket.
 * The value is the identifier the visitor submitted (never the resolved user), so keying on it reveals nothing about
 * which accounts exist.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class UsernameNormalizer
{
    /**
     * Nested request bags checked before flat keys (AuthKit / FormType names).
     *
     * @var list<string>
     */
    public const NESTED_FORM_BAGS = ['login_form'];

    /**
     * Username parameter keys (nested bag first, then root request).
     *
     * @var list<string>
     */
    public const USERNAME_KEYS = ['_username', 'username', 'email'];

    /** Longest username kept, in characters (the `username` column is VARCHAR(255)). */
    public const MAX_LENGTH = 255;

    /**
     * Control (`Cc`, tabs and line breaks included), format (`Cf`: ZWSP, BOM, bidi marks) and line / paragraph separator
     * characters, and every space separator except the ASCII space (NBSP, ideographic space…).
     */
    private const UNUSABLE = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|(?! )\p{Zs}/u';

    /**
     * Normalise a submitted username: edge ASCII spaces trimmed, then lower-cased (`mb_strtolower`), so `Admin` and
     * ` admin ` share one bucket.
     *
     * Non-string, blank and unusable values yield null (REQ-SEC-006 item 1, 3.10.2): invalid UTF-8, control or format
     * characters anywhere (`bob\n`, `bob\t`, ZWSP, BOM, bidi marks), non-ASCII spaces (NBSP) and values longer than
     * {@see MAX_LENGTH} once normalised. They are refused rather than cleaned, so `bob\n` or `bob` + ZWSP never share
     * (or clear) the bucket of `bob`. A request without a usable username is throttled on its IP alone, the stricter
     * bucket, and its successful login clears nothing.
     *
     * @param mixed $value Raw value
     *
     * @return string|null The normalised username or null
     */
    public static function normalize(mixed $value): ?string
    {
        if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8') || 1 === preg_match(self::UNUSABLE, $value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value, ' '));

        return '' === $normalized || mb_strlen($normalized) > self::MAX_LENGTH ? null : $normalized;
    }

    /**
     * Extract the normalised username from the request.
     *
     * Reads the POST fields first ({@code login_form[_username|username|email]}, then the top-level fields). With
     * $fromSecurityAttribute, when no POST field holds a username, falls back to the identifier Symfony's
     * {@code login_throttling} listener stored on the request ({@see SecurityRequestAttributes::LAST_USERNAME}, the
     * passport's UserBadge identifier): {@code json_login} and custom authenticators then get the same
     * IP + username bucket as {@code form_login}.
     *
     * @param Request $request               The login request
     * @param bool    $fromSecurityAttribute Fall back to the passport identifier (option {@code username_from_passport})
     *
     * @return string|null The normalised username or null
     */
    public static function fromRequest(Request $request, bool $fromSecurityAttribute = false): ?string
    {
        foreach (self::candidates($request) as $value) {
            // the first non-blank field decides: an unusable one (e.g. `attacker\t`) yields null instead of falling
            // through to a later field (`email=victim`) whose bucket a successful login would then clear (REQ-SEC-006)
            if (\is_string($value) && '' !== trim($value)) {
                return self::normalize($value);
            }
        }

        if ($fromSecurityAttribute) {
            return self::normalize($request->attributes->get(SecurityRequestAttributes::LAST_USERNAME));
        }

        return null;
    }

    /**
     * The username fields in order, read lazily: a later field is never read once an earlier one decides (an array in
     * a later field would make InputBag::get() throw).
     *
     * @return \Generator<int, mixed>
     */
    private static function candidates(Request $request): \Generator
    {
        foreach (self::NESTED_FORM_BAGS as $bag) {
            $nested = $request->request->all($bag);
            foreach (self::USERNAME_KEYS as $key) {
                yield $nested[$key] ?? null;
            }
        }
        foreach (self::USERNAME_KEYS as $key) {
            yield $request->request->get($key);
        }
    }
}
