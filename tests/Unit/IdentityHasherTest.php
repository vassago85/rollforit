<?php

use App\Promotion\IdentityHasher;

it('hashes a Shopify customer id the same way twice and ignores junk', function () {
    expect(IdentityHasher::hashShopifyCustomerId('4242'))
        ->toBe(IdentityHasher::hashShopifyCustomerId('4242'))
        ->not->toBe(IdentityHasher::hashShopifyCustomerId('4243'))
        ->and(IdentityHasher::hashShopifyCustomerId(''))->toBeNull()
        ->and(IdentityHasher::hashShopifyCustomerId('abc'))->toBeNull()
        ->and(IdentityHasher::hashShopifyCustomerId('gid://shopify/Customer/4242'))
        ->toBe(IdentityHasher::hashShopifyCustomerId('4242'));
});
