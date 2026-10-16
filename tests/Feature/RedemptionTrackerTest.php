<?php

use App\Models\Roll;
use App\Promotion\EmailHasher;
use App\Promotion\RedemptionTracker;
use Carbon\CarbonImmutable;

function winningRoll(int $shopId, string $code, int $payout = 50000): Roll
{
    return Roll::create([
        'shop_id' => $shopId,
        'email_hash' => EmailHasher::hash('winner-'.bin2hex(random_bytes(2)).'@example.com'),
        'promo_date' => now('Africa/Johannesburg')->toDateString(),
        'cart_subtotal' => 80000, 'eligible_subtotal' => 80000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => $payout, 'discount_code' => $code,
        'code_status' => Roll::CODE_CREATED,
    ]);
}

it('marks a winning roll as redeemed when its code appears on a paid order', function () {
    $shop = makeShop();
    $roll = winningRoll($shop->id, 'ROLL-ABCD1234', 50000);
    $placed = CarbonImmutable::parse('2026-10-16 11:00', 'UTC');

    $redeemed = app(RedemptionTracker::class)->markOrderRedemption(
        $shop, 'gid://shopify/Order/99', ['ROLL-ABCD1234'], 50000, $placed,
    );

    expect($redeemed?->id)->toBe($roll->id);
    $fresh = $roll->fresh();
    expect($fresh->redeemed_at->toIso8601String())->toBe($placed->toIso8601String())
        ->and($fresh->redeemed_order_id)->toBe('gid://shopify/Order/99')
        ->and($fresh->redeemed_amount)->toBe(50000);
});

it('matches case-insensitively — Shopify sometimes lower-cases the code in webhooks', function () {
    $shop = makeShop();
    winningRoll($shop->id, 'ROLL-XYZZZZ11', 25000);

    $redeemed = app(RedemptionTracker::class)->markOrderRedemption(
        $shop, 'gid://shopify/Order/1', ['roll-xyzzzz11'], 25000, CarbonImmutable::now(),
    );

    expect($redeemed)->not->toBeNull()
        ->and($redeemed->fresh()->redeemed_amount)->toBe(25000);
});

it('ignores orders with no discount codes', function () {
    $shop = makeShop();
    winningRoll($shop->id, 'ROLL-UNUSEDXX', 10000);

    expect(app(RedemptionTracker::class)->markOrderRedemption(
        $shop, 'gid://shopify/Order/5', [], 10000, CarbonImmutable::now(),
    ))->toBeNull();
});

it('ignores orders whose codes do not belong to any winning roll', function () {
    $shop = makeShop();
    winningRoll($shop->id, 'ROLL-OURCODEX', 10000);

    expect(app(RedemptionTracker::class)->markOrderRedemption(
        $shop, 'gid://shopify/Order/5', ['SOMEBODY-ELSE-SALE', 'SPRING2026'], 10000, CarbonImmutable::now(),
    ))->toBeNull();
});

it('caps the redeemed amount at the issued payout even when the order discount is larger', function () {
    $shop = makeShop();
    $roll = winningRoll($shop->id, 'ROLL-CAPCHECK', 30000);

    app(RedemptionTracker::class)->markOrderRedemption(
        $shop, 'gid://shopify/Order/1', ['ROLL-CAPCHECK'], 90000, CarbonImmutable::now(),
    );

    // The 90000 came from stacking with a sitewide promo; our code is single-use
    // and fixed-amount, so we only credit our own 30000 cap.
    expect($roll->fresh()->redeemed_amount)->toBe(30000);
});

it('is idempotent — a retried webhook does not overwrite the original redemption', function () {
    $shop = makeShop();
    $roll = winningRoll($shop->id, 'ROLL-DOUBLE11', 20000);
    $first = CarbonImmutable::parse('2026-10-16 08:00', 'UTC');
    $second = CarbonImmutable::parse('2026-10-16 09:00', 'UTC');

    app(RedemptionTracker::class)->markOrderRedemption($shop, 'gid://shopify/Order/A', ['ROLL-DOUBLE11'], 20000, $first);
    app(RedemptionTracker::class)->markOrderRedemption($shop, 'gid://shopify/Order/B', ['ROLL-DOUBLE11'], 20000, $second);

    $fresh = $roll->fresh();
    expect($fresh->redeemed_order_id)->toBe('gid://shopify/Order/A')
        ->and($fresh->redeemed_at->toIso8601String())->toBe($first->toIso8601String());
});
