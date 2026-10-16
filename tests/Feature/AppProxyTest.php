<?php

use App\Shopify\AppProxySignature;

it('accepts a correctly signed status request', function () {
    $shop = makeShop();

    $this->getJson('/api/proxy/status?'.proxyQuery($shop))
        ->assertOk()
        ->assertJson(['open' => true, 'logged_in' => false, 'banner' => 'Spend R500, win up to R1,000 off. T&Cs apply.'])
        ->assertJsonPath('terms_url', 'https://shop.example.co.za/pages/roll-for-it-terms')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('rejects a tampered request', function () {
    $shop = makeShop();
    $query = proxyQuery($shop);

    $this->getJson('/api/proxy/status?'.str_replace('timestamp=', 'timestamp=1', $query))->assertStatus(401);
    $this->getJson('/api/proxy/status?'.$query.'&extra=1')->assertStatus(401);
});

it('rejects a request with no signature', function () {
    $shop = makeShop();

    $this->getJson('/api/proxy/status?shop='.$shop->domain.'&timestamp='.time())->assertStatus(401);
});

it('rejects a stale signature', function () {
    $shop = makeShop();

    $this->getJson('/api/proxy/status?'.proxyQuery($shop, [], time() - 301))->assertStatus(401);
});

it('rejects a replayed roll signature', function () {
    $shop = makeShop();
    fakeShopify([1 => ['price' => '100.00']]);
    $query = proxyQuery($shop);

    $this->postJson('/api/proxy/roll?'.$query, rollPayload())->assertStatus(422);
    $this->postJson('/api/proxy/roll?'.$query, rollPayload())->assertStatus(409);
});

it('rejects a signature made with another secret', function () {
    $shop = makeShop();
    $query = AppProxySignature::sign(['shop' => $shop->domain, 'timestamp' => (string) time()], 'not-our-secret');

    $this->getJson('/api/proxy/status?'.$query)->assertStatus(401);
});

it('returns 404 for an unknown or uninstalled shop', function () {
    $shop = makeShop([], ['uninstalled_at' => now()]);

    $this->getJson('/api/proxy/status?'.proxyQuery($shop))->assertNotFound();
});

it('validates the roll body', function () {
    $shop = makeShop();

    $this->postJson('/api/proxy/roll?'.proxyQuery($shop), rollPayload(['email' => 'not-an-email', 'picked_number' => 11]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'picked_number']);
});

it('marks status as logged in when Shopify signed a customer id onto the proxy request', function () {
    $shop = makeShop();

    $this->getJson('/api/proxy/status?'.proxyQuery($shop, ['logged_in_customer_id' => '4242']))
        ->assertOk()
        ->assertJson(['logged_in' => true]);
});

it('lets a signed-in customer roll without an email', function () {
    $shop = makeShop();
    forceNextWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);

    $result = test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['logged_in_customer_id' => '4242', 'nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertOk()
        ->json();

    expect($result['won'])->toBeTrue()
        ->and(\App\Models\Roll::first()->email_hash)->toBeNull()
        ->and(\App\Models\Roll::first()->shopify_customer_hash)->toBe(\App\Promotion\IdentityHasher::hashShopifyCustomerId('4242'));
});

it('lets a guest roll with no email when an IP is present', function () {
    $shop = makeShop();
    forceNextWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);

    $result = test()->withHeaders(['Accept' => 'application/json', 'X-Forwarded-For' => '198.51.100.20'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertOk()
        ->json();

    expect($result['won'])->toBeTrue()
        ->and(\App\Models\Roll::first()->email_hash)->toBeNull()
        ->and(\App\Models\Roll::first()->ip)->toBe('198.51.100.20');
});

it('returns the same result if the same guest IP rolls twice', function () {
    $shop = makeShop();
    forceNextWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);
    $headers = ['Accept' => 'application/json', 'X-Forwarded-For' => '198.51.100.21'];

    $first = test()->withHeaders($headers)
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertOk()->json();
    $second = test()->withHeaders($headers)
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertOk()->json();

    expect($second['repeat'])->toBeTrue()
        ->and($second['won'])->toBe($first['won'])
        ->and(\App\Models\Roll::count())->toBe(1);
});

it('returns the same result if the same Shopify customer rolls twice', function () {
    $shop = makeShop();
    forceNextWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);

    $first = test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['logged_in_customer_id' => '99', 'nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertOk()->json();
    $second = test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['logged_in_customer_id' => '99', 'nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null]))
        ->assertOk()->json();

    expect($second['repeat'])->toBeTrue()
        ->and($second['won'])->toBe($first['won'])
        ->and(\App\Models\Roll::count())->toBe(1);
});
