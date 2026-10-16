<?php

use App\Models\PrivacyRequest;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\EmailHasher;
use App\Promotion\IdentityHasher;
use Illuminate\Testing\TestResponse;

function webhook(Shop $shop, string $topic, array $payload, ?string $hmac = null): TestResponse
{
    $body = json_encode($payload);
    $hmac ??= base64_encode(hash_hmac('sha256', $body, config('shopify.api_secret'), true));

    return test()->call('POST', '/api/webhooks', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop->domain,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
    ], $body);
}

function rollFor(Shop $shop, string $email): Roll
{
    return Roll::create([
        'shop_id' => $shop->id, 'email_hash' => EmailHasher::hash($email), 'promo_date' => now()->toDateString(),
        'cart_subtotal' => 60000, 'eligible_subtotal' => 60000, 'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
    ]);
}

it('returns 401 for a bad HMAC on every topic', function (string $topic) {
    $shop = makeShop();

    webhook($shop, $topic, ['shop_domain' => $shop->domain], 'bad')->assertStatus(401);
    expect(Shop::count())->toBe(1);
})->with(['app/uninstalled', 'customers/data_request', 'customers/redact', 'shop/redact']);

it('deletes the customer rolls on customers/redact', function () {
    $shop = makeShop();
    rollFor($shop, 'gone@example.com');
    rollFor($shop, 'stay@example.com');

    webhook($shop, 'customers/redact', [
        'shop_domain' => $shop->domain,
        'customer' => ['id' => 42, 'email' => 'Gone@Example.com'],
        'orders_to_redact' => [],
    ])->assertOk();

    expect(Roll::count())->toBe(1)
        ->and(Roll::first()->email_hash)->toBe(EmailHasher::hash('stay@example.com'))
        ->and(PrivacyRequest::first()->matched_rolls)->toBe(1);
});

it('logs customers/data_request for the merchant', function () {
    $shop = makeShop();
    rollFor($shop, 'ask@example.com');

    webhook($shop, 'customers/data_request', [
        'shop_domain' => $shop->domain,
        'customer' => ['id' => 7, 'email' => 'ask@example.com'],
        'data_request' => ['id' => 99],
    ])->assertOk();

    $request = PrivacyRequest::first();
    expect($request->topic)->toBe('customers/data_request')
        ->and($request->matched_rolls)->toBe(1)
        ->and($request->shopify_customer_id)->toBe('7');
});

it('deletes all shop data on shop/redact', function () {
    $shop = makeShop();
    rollFor($shop, 'a@example.com');

    webhook($shop, 'shop/redact', ['shop_domain' => $shop->domain])->assertOk();

    expect(Shop::count())->toBe(0)->and(Roll::count())->toBe(0);
});

it('marks the shop uninstalled and disables the promotion on app/uninstalled', function () {
    $shop = makeShop();

    webhook($shop, 'app/uninstalled', ['domain' => $shop->domain])->assertOk();

    $shop->refresh();
    expect($shop->access_token)->toBeNull()
        ->and($shop->uninstalled_at)->not->toBeNull()
        ->and($shop->settings->enabled)->toBeFalse();
});

it('fulfils the most recent matching roll on orders/paid', function () {
    $shop = makeShop();
    $roll = Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => EmailHasher::hash('buyer@example.com'),
        'shopify_customer_hash' => IdentityHasher::hashShopifyCustomerId('777'),
        'promo_date' => now()->subDays(7)->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
    ]);

    webhook($shop, 'orders/paid', [
        'shop_domain' => $shop->domain,
        'admin_graphql_api_id' => 'gid://shopify/Order/5001',
        'customer' => ['id' => 777, 'email' => 'buyer@example.com'],
        'email' => 'buyer@example.com',
        'browser_ip' => '203.0.113.9',
        'created_at' => now()->toIso8601String(),
        'test' => false,
    ])->assertOk();

    expect($roll->refresh()->fulfilled_at)->not->toBeNull()
        ->and($roll->fulfilled_order_id)->toBe('gid://shopify/Order/5001');
});

it('matches a guest roll by browser_ip when there is no account', function () {
    $shop = makeShop();
    $roll = Roll::create([
        'shop_id' => $shop->id,
        'ip' => '198.51.100.44',
        'promo_date' => now()->subDays(7)->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
    ]);

    webhook($shop, 'orders/paid', [
        'shop_domain' => $shop->domain,
        'admin_graphql_api_id' => 'gid://shopify/Order/5002',
        'email' => '',
        'browser_ip' => '198.51.100.44',
        'created_at' => now()->toIso8601String(),
        'test' => false,
    ])->assertOk();

    expect($roll->refresh()->fulfilled_at)->not->toBeNull();
});

it('ignores test orders', function () {
    $shop = makeShop();
    $roll = rollFor($shop, 'buyer@example.com');

    webhook($shop, 'orders/paid', [
        'shop_domain' => $shop->domain,
        'admin_graphql_api_id' => 'gid://shopify/Order/5003',
        'email' => 'buyer@example.com',
        'created_at' => now()->toIso8601String(),
        'test' => true,
    ])->assertOk();

    expect($roll->refresh()->fulfilled_at)->toBeNull();
});

it('re-locks a roll when the order is cancelled', function () {
    $shop = makeShop();
    $roll = Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => EmailHasher::hash('buyer@example.com'),
        'promo_date' => now()->subDays(7)->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
        'fulfilled_at' => now(),
        'fulfilled_order_id' => 'gid://shopify/Order/5004',
    ]);

    webhook($shop, 'orders/cancelled', [
        'shop_domain' => $shop->domain,
        'admin_graphql_api_id' => 'gid://shopify/Order/5004',
    ])->assertOk();

    $roll->refresh();
    expect($roll->fulfilled_at)->toBeNull()
        ->and($roll->fulfilled_order_id)->toBeNull();
});

it('marks a winning roll as redeemed when the order used its code', function () {
    $shop = makeShop();
    $roll = Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => EmailHasher::hash('winner@example.com'),
        'promo_date' => now()->toDateString(),
        'cart_subtotal' => 80000, 'eligible_subtotal' => 80000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => 50000, 'discount_code' => 'ROLL-WON11111',
        'code_status' => Roll::CODE_CREATED,
    ]);

    webhook($shop, 'orders/paid', [
        'shop_domain' => $shop->domain,
        'admin_graphql_api_id' => 'gid://shopify/Order/9000',
        'customer' => ['id' => 1, 'email' => 'winner@example.com'],
        'email' => 'winner@example.com',
        'browser_ip' => '203.0.113.1',
        'created_at' => '2026-10-16T11:30:00Z',
        'test' => false,
        'discount_codes' => [
            ['code' => 'ROLL-WON11111', 'amount' => '500.00', 'type' => 'fixed_amount'],
        ],
        'total_discounts' => '500.00',
    ])->assertOk();

    $fresh = $roll->refresh();
    expect($fresh->redeemed_at)->not->toBeNull()
        ->and($fresh->redeemed_order_id)->toBe('gid://shopify/Order/9000')
        ->and($fresh->redeemed_amount)->toBe(50000);
});

it('leaves other winning rolls untouched when the order used a different code', function () {
    $shop = makeShop();
    $redeemedRoll = Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => EmailHasher::hash('a@example.com'),
        'promo_date' => now()->toDateString(),
        'cart_subtotal' => 80000, 'eligible_subtotal' => 80000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => 50000, 'discount_code' => 'ROLL-AAAA1111',
        'code_status' => Roll::CODE_CREATED,
    ]);
    $untouchedRoll = Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => EmailHasher::hash('b@example.com'),
        'promo_date' => now()->toDateString(),
        'cart_subtotal' => 80000, 'eligible_subtotal' => 80000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => 50000, 'discount_code' => 'ROLL-BBBB2222',
        'code_status' => Roll::CODE_CREATED,
    ]);

    webhook($shop, 'orders/paid', [
        'shop_domain' => $shop->domain,
        'admin_graphql_api_id' => 'gid://shopify/Order/9001',
        'email' => 'a@example.com',
        'browser_ip' => '203.0.113.2',
        'created_at' => '2026-10-16T11:30:00Z',
        'test' => false,
        'discount_codes' => [['code' => 'ROLL-AAAA1111', 'amount' => '500.00', 'type' => 'fixed_amount']],
        'total_discounts' => '500.00',
    ])->assertOk();

    expect($redeemedRoll->refresh()->redeemed_at)->not->toBeNull()
        ->and($untouchedRoll->refresh()->redeemed_at)->toBeNull();
});
