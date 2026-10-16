<?php

use App\Models\Roll;
use App\Promotion\PromoMetricsService;

it('computes today metrics when guest rolls with null email_hash are present', function () {
    $shop = makeShop(['promo_days' => [(int) now('Africa/Johannesburg')->dayOfWeekIso]]);
    Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => null,
        'ip' => '198.51.100.80',
        'promo_date' => now('Africa/Johannesburg')->toDateString(),
        'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
        'picked_number' => 1, 'displayed_number' => 2,
        'won' => false, 'payout' => 0,
    ]);
    fakeShopify([], [], ['PromoOrders' => fn () => ['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => []]]]]);

    expect(app(PromoMetricsService::class)->today($shop, fresh: true))->not->toBeNull();
});
