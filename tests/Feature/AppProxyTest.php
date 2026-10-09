<?php

use App\Shopify\AppProxySignature;

it('accepts a correctly signed status request', function () {
    $shop = makeShop();

    $this->getJson('/api/proxy/status?'.proxyQuery($shop))
        ->assertOk()
        ->assertJson(['open' => true, 'banner' => 'Spend R500, win up to R1,000 off. T&Cs apply.'])
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
