<?php

namespace App\Promotion;

use RuntimeException;

/**
 * Identities (email, phone) are never stored. Only a keyed hash of the normalised
 * value is kept, so Shopify's customers/redact webhook can match either channel.
 */
final class IdentityHasher
{
    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * ZA numbers normalise to E.164 (+27…). Anything else keeps its leading '+' plus digits.
     * Local 0XX becomes +27XX. Length is enforced so a scanner-smudged "0" doesn't accidentally
     * match somebody else's number.
     */
    public static function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone);
        if (! $digits) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = '+27'.substr($digits, 1);
        }
        if (! str_starts_with($digits, '+')) {
            $digits = '+'.$digits;
        }

        $len = strlen($digits);

        return ($len >= 9 && $len <= 16) ? $digits : null;
    }

    public static function hashEmail(string $email): string
    {
        return self::hmac(self::normaliseEmail($email));
    }

    public static function hashPhone(string $phone): ?string
    {
        $normalised = self::normalisePhone($phone);

        return $normalised === null ? null : self::hmac($normalised);
    }

    private static function hmac(string $value): string
    {
        $pepper = (string) config('shopify.roll.email_pepper');
        if ($pepper === '') {
            throw new RuntimeException('ROLL_EMAIL_PEPPER is not configured.');
        }

        return hash_hmac('sha256', $value, $pepper);
    }
}
