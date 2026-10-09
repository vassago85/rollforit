<?php

namespace App\Promotion;

use App\Models\Shop;
use App\Models\ShopSettings;
use InvalidArgumentException;

/**
 * Merchant-only test mode: a short-lived secret link that forces a win or a loss.
 */
final class PreviewLinks
{
    /**
     * @return array{token: string, url: string, expires_at: string}
     */
    public static function issue(Shop $shop, ShopSettings $settings, string $outcome): array
    {
        if (! in_array($outcome, ['win', 'loss'], true)) {
            throw new InvalidArgumentException('Outcome must be win or loss.');
        }

        $token = bin2hex(random_bytes(24));
        $settings->forceFill([
            'preview_token_hash' => hash('sha256', $token),
            'preview_outcome' => $outcome,
            'preview_expires_at' => now()->addMinutes(config('shopify.test_mode.preview_minutes')),
        ])->save();

        return [
            'token' => $token,
            'url' => $shop->storefrontUrl().'/cart?rfi_preview='.$token,
            'expires_at' => $settings->preview_expires_at->toIso8601String(),
        ];
    }

    public static function isValid(ShopSettings $settings, ?string $token): bool
    {
        if (! $token || ! $settings->preview_token_hash || ! $settings->preview_expires_at) {
            return false;
        }
        if ($settings->preview_expires_at->isPast()) {
            return false;
        }

        return hash_equals($settings->preview_token_hash, hash('sha256', $token));
    }
}
