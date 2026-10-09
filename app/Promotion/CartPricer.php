<?php

namespace App\Promotion;

use App\Models\Shop;
use App\Models\ShopSettings;
use App\Shopify\ShopifyAdmin;
use App\Support\Money;

/**
 * Re-prices the lines the widget reports using the Admin API. Client-side
 * prices are never used.
 */
class CartPricer
{
    public function __construct(private readonly ShopifyAdmin $admin) {}

    /**
     * @param  array<int, array{variant_id?: mixed, quantity?: mixed}>  $lines
     */
    public function price(Shop $shop, ShopSettings $settings, array $lines): PricedCart
    {
        $quantities = $this->normaliseLines($lines);
        if ($quantities === []) {
            return new PricedCart(0, 0, 0, []);
        }

        $excluded = array_values(array_filter(
            $settings->excludedCollectionIds(),
            fn ($id) => preg_match('#^gid://shopify/Collection/\d+$#', $id) === 1,
        ));

        $aliases = '';
        foreach ($excluded as $i => $collectionId) {
            $aliases .= sprintf(' ex%d: inCollection(id: "%s")', $i, $collectionId);
        }

        $query = <<<GQL
            query CartPricing(\$ids: [ID!]!) {
              nodes(ids: \$ids) {
                ... on ProductVariant {
                  id
                  price
                  product { id isGiftCard{$aliases} }
                }
              }
            }
            GQL;

        $data = $this->admin->graphql($shop, $query, ['ids' => array_keys($quantities)]);

        $cart = 0;
        $giftCards = 0;
        $eligible = 0;
        $eligibleProducts = [];

        foreach ($data['nodes'] ?? [] as $node) {
            if (! is_array($node) || empty($node['id']) || ! isset($quantities[$node['id']])) {
                continue;
            }

            $lineTotal = Money::toCents((string) $node['price']) * $quantities[$node['id']];
            $product = $node['product'] ?? [];
            $cart += $lineTotal;

            if (! empty($product['isGiftCard'])) {
                $giftCards += $lineTotal;

                continue;
            }

            $isExcluded = false;
            foreach (array_keys($excluded) as $i) {
                if (! empty($product["ex{$i}"])) {
                    $isExcluded = true;
                    break;
                }
            }

            if (! $isExcluded) {
                $eligible += $lineTotal;
                $eligibleProducts[$product['id']] = true;
            }
        }

        return new PricedCart($cart, $giftCards, $eligible, array_keys($eligibleProducts));
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return array<string, int> Variant GID => quantity.
     */
    private function normaliseLines(array $lines): array
    {
        $maxLines = (int) config('shopify.roll.max_cart_lines');
        $maxQty = (int) config('shopify.roll.max_line_quantity');
        $result = [];

        foreach (array_slice($lines, 0, $maxLines) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $raw = (string) ($line['variant_id'] ?? '');
            $numeric = preg_replace('#^gid://shopify/ProductVariant/#', '', $raw);
            $qty = (int) ($line['quantity'] ?? 0);
            if (! ctype_digit($numeric) || $qty < 1) {
                continue;
            }

            $gid = 'gid://shopify/ProductVariant/'.$numeric;
            $result[$gid] = min($maxQty, ($result[$gid] ?? 0) + $qty);
        }

        return $result;
    }
}
