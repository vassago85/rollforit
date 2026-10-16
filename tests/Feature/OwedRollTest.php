<?php

use App\Models\Roll;
use App\Promotion\EmailHasher;
use App\Promotion\IdentityHasher;

it('lets a first-time guest roll even with no history', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);

    test()->withHeaders(['Accept' => 'application/json', 'X-Forwarded-For' => '198.51.100.60'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertOk();
});

it('blocks the next roll when the previous roll was not paid', function () {
    $shop = makeShop();
    Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => EmailHasher::hash('customer@example.com'),
        'promo_date' => now()->subDays(7)->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
    ]);
    fakeShopify([1 => ['price' => '600.00']]);

    $response = roll($shop)->assertStatus(422);
    expect($response->json('error'))->toBe('owed')
        ->and(Roll::count())->toBe(1);
});

it('allows the next roll once the previous one is fulfilled', function () {
    $shop = makeShop();
    Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => EmailHasher::hash('customer@example.com'),
        'promo_date' => now()->subDays(7)->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
        'fulfilled_at' => now()->subDay(),
        'fulfilled_order_id' => 'gid://shopify/Order/9000',
    ]);
    fakeShopify([1 => ['price' => '600.00']]);

    roll($shop)->assertOk();
    expect(Roll::count())->toBe(2);
});

it('shows an owed reason on /status when the roller still owes an order', function () {
    $shop = makeShop();
    Roll::create([
        'shop_id' => $shop->id,
        'shopify_customer_hash' => IdentityHasher::hashShopifyCustomerId('4242'),
        'promo_date' => now()->subDays(7)->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 3, 'displayed_number' => 3,
        'won' => true, 'payout' => 50000,
        'discount_code' => 'ROLL-OWED1',
        'code_status' => Roll::CODE_CREATED,
    ]);

    $response = test()->getJson('/api/proxy/status?'.proxyQuery($shop, ['logged_in_customer_id' => '4242']))
        ->assertOk()
        ->json();

    expect($response['reason'])->toBe('owed')
        ->and($response['owed_roll']['won'])->toBeTrue()
        ->and($response['owed_roll']['code'])->toBe('ROLL-OWED1');
});

it('does not fire owed when the previous roll was fulfilled', function () {
    $shop = makeShop();
    Roll::create([
        'shop_id' => $shop->id,
        'shopify_customer_hash' => IdentityHasher::hashShopifyCustomerId('4242'),
        'promo_date' => now()->subDays(7)->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 3, 'displayed_number' => 3,
        'won' => false, 'payout' => 0,
        'fulfilled_at' => now()->subDay(),
        'fulfilled_order_id' => 'gid://shopify/Order/9001',
    ]);

    $response = test()->getJson('/api/proxy/status?'.proxyQuery($shop, ['logged_in_customer_id' => '4242']))
        ->assertOk()
        ->json();

    expect($response['reason'])->toBeNull()
        ->and($response['owed_roll'])->toBeNull();
});
