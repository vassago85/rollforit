<?php
/**
 * One-off dev helper: ensures a dev shop exists in Roll for It and that its POS secret
 * matches the one HuntexPos appsettings.Development.json carries. Idempotent; safe to
 * re-run after `php artisan migrate:fresh` or after rotating the shared dev secret.
 *
 * Run with:  php artisan tinker --execute="require 'scripts/seed-dev-pos.php';"
 */

use App\Models\Shop;

$domain = 'dev-shop.myshopify.com';
$posSecret = 'dev-pos-local-only-abcdefghijklmnopqrstuvwx';

$shop = Shop::firstOrCreate(
    ['domain' => $domain],
    [
        'name' => 'Dev Shop (local)',
        'email' => 'owner@dev.local',
        'access_token' => 'shpat_devonly',
        'scopes' => config('shopify.scopes'),
        'timezone' => 'Africa/Johannesburg',
        'currency' => 'ZAR',
        'primary_domain_url' => 'http://localhost:8089',
        'installed_at' => now(),
    ],
);

$settings = $shop->settingsOrCreate();
$settings->forceFill([
    'enabled' => true,
    'odds_mode' => \App\Models\ShopSettings::MODE_GUARANTEED,
    'odds_n' => 10,
    'promo_days' => [1, 2, 3, 4, 5, 6, 7],
    'start_time' => '00:00',
    'end_time' => '23:59',
    'min_order' => 50000,  // R500
    'max_payout' => 100000, // R1,000
    'promoter_name' => 'Dev Shop',
    'promoter_address' => 'Local',
    'promoter_email' => 'owner@dev.local',
    'terms_url' => 'http://localhost:8089/terms',
    'terms_published_at' => now(),
    'pos_enabled' => true,
    'pos_secret' => $posSecret,
    'pos_secret_last_four' => substr($posSecret, -4),
    'pos_secret_rotated_at' => now(),
])->save();

// Force the next POS roll to WIN so the local demo is interesting without 10 rolls.
\App\Models\Block::where('shop_id', $shop->id)->whereNull('completed_at')->delete();
\App\Models\Block::create([
    'shop_id' => $shop->id,
    'size' => 10,
    'winning_position' => 1,
    'next_position' => 1,
]);

echo "Dev shop ready: {$shop->domain} (id {$shop->id})\n";
echo "POS secret:     {$posSecret}\n";
echo "Base URL:       http://localhost:8089/api/pos\n";
