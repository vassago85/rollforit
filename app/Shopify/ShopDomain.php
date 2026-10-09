<?php

namespace App\Shopify;

final class ShopDomain
{
    public static function isValid(?string $domain): bool
    {
        return is_string($domain)
            && preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/i', $domain) === 1;
    }
}
