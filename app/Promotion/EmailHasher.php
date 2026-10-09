<?php

namespace App\Promotion;

/**
 * Backwards-compatible façade over IdentityHasher. New code should call IdentityHasher directly.
 */
final class EmailHasher
{
    public static function normalise(string $email): string
    {
        return IdentityHasher::normaliseEmail($email);
    }

    public static function hash(string $email): string
    {
        return IdentityHasher::hashEmail($email);
    }
}
