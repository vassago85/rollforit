<?php

namespace App\Shopify;

/**
 * HuntexPos ↔ Roll for It signing scheme.
 *
 * Each request carries three headers:
 *   X-RFI-Shop:       the shop domain (so we can look up the secret)
 *   X-RFI-Timestamp:  unix seconds (must be within +/- 300s of our clock)
 *   X-RFI-Nonce:      opaque random value, replay-rejected via cache
 *   X-RFI-Signature:  hex HMAC-SHA256 of "<timestamp>.<nonce>.<raw body>"
 */
final class PosSignature
{
    public const WINDOW_SECONDS = 300;

    public static function compute(string $timestamp, string $nonce, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
    }

    public static function verify(string $timestamp, string $nonce, string $body, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '' || $timestamp === '' || $nonce === '') {
            return false;
        }

        $expected = self::compute($timestamp, $nonce, $body, $secret);

        return hash_equals($expected, $signature);
    }

    public static function withinWindow(string $timestamp, ?int $now = null): bool
    {
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $delta = abs(($now ?? time()) - (int) $timestamp);

        return $delta <= self::WINDOW_SECONDS;
    }
}
