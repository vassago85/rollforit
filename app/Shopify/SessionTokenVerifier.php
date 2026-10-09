<?php

namespace App\Shopify;

use App\Shopify\Exceptions\InvalidSessionToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Validates App Bridge ID tokens (HS256 signed with the app's client secret).
 */
class SessionTokenVerifier
{
    /**
     * @return array{shop: string, sub: ?string, claims: array<string, mixed>}
     */
    public function verify(string $token): array
    {
        $secret = (string) config('shopify.api_secret');
        if ($secret === '') {
            throw new InvalidSessionToken('App secret is not configured.');
        }

        JWT::$leeway = 5;

        try {
            $claims = (array) JWT::decode($token, new Key($secret, 'HS256'));
        } catch (Throwable $e) {
            throw new InvalidSessionToken('Invalid ID token: '.$e->getMessage());
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array(config('shopify.api_key'), $audiences, true)) {
            throw new InvalidSessionToken('ID token audience does not match this app.');
        }

        $issHost = parse_url((string) ($claims['iss'] ?? ''), PHP_URL_HOST);
        $destHost = parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST);
        if (! $issHost || $issHost !== $destHost) {
            throw new InvalidSessionToken('ID token issuer and destination do not match.');
        }

        if (! ShopDomain::isValid($destHost)) {
            throw new InvalidSessionToken('ID token destination is not a myshopify.com domain.');
        }

        return [
            'shop' => strtolower($destHost),
            'sub' => isset($claims['sub']) ? (string) $claims['sub'] : null,
            'claims' => $claims,
        ];
    }
}
