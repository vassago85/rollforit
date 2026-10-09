<?php

use App\Shopify\AppProxySignature;

it('matches the documented Shopify example signature', function () {
    // Example from shopify.dev "Authenticate app proxies" (secret "hush").
    $query = 'extra=1&extra=2&shop=shop-name.myshopify.com&logged_in_customer_id=1&path_prefix=%2Fapps%2Fawesome_reviews&timestamp=1317327555'
        .'&signature=4c68c8624d737112c91818c11017d24d334b524cb5c2b8ba08daa056f7395ddb';

    expect(AppProxySignature::verify($query, 'hush'))->toBeTrue();
});

it('rejects a tampered query string', function () {
    $query = AppProxySignature::sign(['shop' => 'a.myshopify.com', 'timestamp' => '1'], 'secret');

    expect(AppProxySignature::verify(str_replace('a.myshopify.com', 'b.myshopify.com', $query), 'secret'))->toBeFalse()
        ->and(AppProxySignature::verify($query, 'other-secret'))->toBeFalse()
        ->and(AppProxySignature::verify($query, ''))->toBeFalse();
});

it('joins repeated keys with commas before signing', function () {
    $signed = AppProxySignature::sign(['extra' => ['1', '2'], 'shop' => 'a.myshopify.com'], 'secret');
    $params = AppProxySignature::parse($signed);

    expect($params['extra'])->toBe(['1', '2'])
        ->and($params['signature'][0])->toBe(hash_hmac('sha256', 'extra=1,2shop=a.myshopify.com', 'secret'));
});
