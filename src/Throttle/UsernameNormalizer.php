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

    /**
     * Normalise a submitted username (trim + lower-case); non-string or blank values yield null.
     *
     * @param mixed $value Raw value
     *
     * @return string|null The normalised username or null
     */
    public static function normalize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return '' === $normalized ? null : $normalized;
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
        foreach (self::NESTED_FORM_BAGS as $bag) {
            $nested = $request->request->all($bag);

            foreach (self::USERNAME_KEYS as $key) {
                $normalized = self::normalize($nested[$key] ?? null);

                if (null !== $normalized) {
                    return $normalized;
                }
            }
        }

        foreach (self::USERNAME_KEYS as $key) {
            $normalized = self::normalize($request->request->get($key));

            if (null !== $normalized) {
                return $normalized;
            }
        }

        if ($fromSecurityAttribute) {
            return self::normalize($request->attributes->get(SecurityRequestAttributes::LAST_USERNAME));
        }

        return null;
    }
}
