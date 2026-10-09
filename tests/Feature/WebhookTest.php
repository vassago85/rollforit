<?php

use App\Models\PrivacyRequest;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\EmailHasher;
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
