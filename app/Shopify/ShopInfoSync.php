<?php

namespace App\Shopify;

use App\Models\Shop;

class ShopInfoSync
{
    public function __construct(private readonly ShopifyAdmin $admin) {}

    public function sync(Shop $shop): Shop
    {
        $data = $this->admin->graphql($shop, <<<'GQL'
            query ShopInfo {
              shop {
                name
                email
                ianaTimezone
                currencyCode
                primaryDomain { url }
              }
            }
            GQL);

        $info = $data['shop'] ?? [];
        $shop->fill([
            'name' => $info['name'] ?? $shop->name,
            'email' => $info['email'] ?? $shop->email,
            'timezone' => $info['ianaTimezone'] ?? $shop->timezone,
            'currency' => $info['currencyCode'] ?? $shop->currency,
            'primary_domain_url' => $info['primaryDomain']['url'] ?? $shop->primary_domain_url,
        ])->save();

        return $shop;
    }
}
