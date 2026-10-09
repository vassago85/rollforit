<?php

it('serves the embedded admin pages with App Bridge, Polaris and a frame-ancestors policy', function (string $url) {
    $response = $this->get($url.'?shop=mctactical.myshopify.com&host=abc&embedded=1')->assertOk();

    $response->assertSee('<meta name="shopify-api-key" content="test-client-id">', false)
        ->assertSee('https://cdn.shopify.com/shopifycloud/app-bridge.js', false)
        ->assertSee('https://cdn.shopify.com/shopifycloud/polaris-1.js', false)
        ->assertSee('<s-app-nav>', false)
        ->assertHeader('Content-Security-Policy', 'frame-ancestors https://mctactical.myshopify.com https://admin.shopify.com;');
})->with(['/', '/terms', '/advert-copy', '/reporting']);

it('falls back to a generic frame-ancestors policy when the shop parameter is invalid', function () {
    $this->get('/?shop=evil.example.com')
        ->assertHeader('Content-Security-Policy', 'frame-ancestors https://*.myshopify.com https://admin.shopify.com;');
});
