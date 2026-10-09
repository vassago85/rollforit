<?php

namespace App\Shopify;

/**
 * App proxy signature: hex HMAC-SHA256 of the query parameters (minus `signature`),
 * each rendered as key=value (repeated keys joined with ","), sorted and concatenated.
 */
final class AppProxySignature
{
    /**
     * @return array<string, list<string>>
     */
    public static function parse(string $queryString): array
    {
        $params = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($key)][] = urldecode($value);
        }

        return $params;
    }

    public static function compute(array $params, string $secret): string
    {
        unset($params['signature']);

        $pieces = [];
        foreach ($params as $key => $values) {
            $pieces[] = $key.'='.implode(',', (array) $values);
        }
        sort($pieces, SORT_STRING);

        return hash_hmac('sha256', implode('', $pieces), $secret);
    }

    public static function verify(string $queryString, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $params = self::parse($queryString);
        $signature = $params['signature'][0] ?? '';

        return $signature !== '' && hash_equals(self::compute($params, $secret), $signature);
    }

    /**
     * Builds a signed query string (used by tests and local tooling).
     *
     * @param  array<string, string|list<string>>  $params
     */
    public static function sign(array $params, string $secret): string
    {
        $normalised = array_map(fn ($v) => (array) $v, $params);
        $normalised['signature'] = [self::compute($normalised, $secret)];

        $pairs = [];
        foreach ($normalised as $key => $values) {
            foreach ($values as $value) {
                $pairs[] = rawurlencode($key).'='.rawurlencode($value);
            }
        }

        return implode('&', $pairs);
    }
}
