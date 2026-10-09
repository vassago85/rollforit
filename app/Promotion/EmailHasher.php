<?php

namespace App\Promotion;

use RuntimeException;

/**
 * Emails are never stored. Only a keyed hash of the normalised address is kept.
 */
final class EmailHasher
{
    public static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function hash(string $email): string
    {
        $pepper = (string) config('shopify.roll.email_pepper');
        if ($pepper === '') {
            throw new RuntimeException('ROLL_EMAIL_PEPPER is not configured.');
        }

        return hash_hmac('sha256', self::normalise($email), $pepper);
    }
}
