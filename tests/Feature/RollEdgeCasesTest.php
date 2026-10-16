<?php

/**
 * Edge cases around the roll endpoint. Each test here covers behaviour the
 * baseline RollTest / AppProxyTest suites don't lock down: shop isolation,
 * exact-boundary caps, preview token lifecycle, cross-channel dedupe, burst
 * rate limiting, three-way payout cap interaction, timezone midnight boundary,
 * and dice-count validation.
 */

use App\Models\Block;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\PreviewLinks;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * Local helper so we don't collide with the file-scoped helpers in RollTest.php.
 * Any guest email in the payload is pre-verified automatically; tests that want
 * to exercise the Mailgun gate itself live in EmailVerificationTest.
 */
function edgeRoll(Shop $shop, array $payload = [], array $headers = []): TestResponse
{
    $email = array_key_exists('email', $payload) ? $payload['email'] : 'customer@example.com';
    if ($email !== null && $email !== '') {
        preVerifyEmail($shop, $email);
    }

    return test()->withHeaders($headers + ['Accept' => 'application/json'])
        ->postJson(
            '/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]),
            rollPayload($payload),
        );
}

function edgeForceWin(Shop $shop): Block
{
    return Block::create([
        'shop_id' => $shop->id, 'size' => 10, 'winning_position' => 1, 'next_position' => 1,
    ]);
}

/**
 * A roll already banked on today's promo_date for an anonymous email hash.
 * Local to this file so we don't depend on the file-scoped helper in RollTest.
 */
function edgeWinnerToday(Shop $shop, int $payout): Roll
{
    return Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => bin2hex(random_bytes(32)),
        'promo_date' => now($shop->timezone)->toDateString(),
        'cart_subtotal' => $payout, 'eligible_subtotal' => $payout,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => $payout,
        'discount_code' => 'ROLL-'.random_int(1000, 9999),
        'code_status' => Roll::CODE_CREATED,
    ]);
}

/* -----------------------------------------------------------------------
 * Shop isolation
 * ----------------------------------------------------------------------- */

it('does not let a roll on shop A block the same shopper from rolling on shop B', function () {
    // Same IP, same (verified) email, different shops: each shop must get its
    // own fresh roll. Verification is scoped per shop + promo_date.
    $shopA = makeShop();
    $shopB = makeShop();
    edgeForceWin($shopA);
    edgeForceWin($shopB);
    fakeShopify([1 => ['price' => '600.00']]);
    $headers = ['Accept' => 'application/json', 'X-Forwarded-For' => '198.51.100.50'];

    $a = edgeRoll($shopA, [], $headers)->assertOk()->json();
    $b = edgeRoll($shopB, [], $headers)->assertOk()->json();

    expect($a['repeat'])->toBeFalse()
        ->and($b['repeat'])->toBeFalse()
        ->and(Roll::where('shop_id', $shopA->id)->count())->toBe(1)
        ->and(Roll::where('shop_id', $shopB->id)->count())->toBe(1);
});

/* -----------------------------------------------------------------------
 * Boundary conditions on budget / winners caps
 * ----------------------------------------------------------------------- */

it('lets the exact Nth win land but sold-outs the next roll when max_winners_per_day is N', function () {
    $shop = makeShop(['max_winners_per_day' => 2]);
    edgeWinnerToday($shop, 10000); // 1 winner banked
    edgeForceWin($shop);        // next roll wins
    fakeShopify([1 => ['price' => '600.00']]);

    // 2nd win lands.
    edgeRoll($shop, ['email' => 'second@example.com'])->assertOk()->assertJson(['won' => true]);
    // 3rd roll from a different roller: sold_out.
    edgeRoll($shop, ['email' => 'third@example.com'])
        ->assertStatus(422)
        ->assertJson(['error' => 'sold_out']);

    expect(Roll::where('won', true)->count())->toBe(2);
});

it('reduces a win to the EXACT remaining budget when the cap is right at the boundary', function () {
    // 90,000 banked out of a 100,000 budget. A fresh win is capped to the 10,000
    // remaining, not the merchant's max_payout of 100,000.
    $shop = makeShop(['daily_budget' => 100000, 'max_payout' => 100000]);
    edgeWinnerToday($shop, 90000);
    edgeForceWin($shop);
    fakeShopify([1 => ['price' => '800.00']]);

    $result = edgeRoll($shop, ['email' => 'edge@example.com'])->assertOk()->json();

    expect($result['won'])->toBeTrue()
        ->and($result['payout'])->toBe(10000);
    // Budget is now exactly spent; next roll must sold_out.
    edgeRoll($shop, ['email' => 'next@example.com'])
        ->assertStatus(422)
        ->assertJson(['error' => 'sold_out']);
});

/* -----------------------------------------------------------------------
 * Guest IP dedupe nuance: shared-cafe scenario
 * ----------------------------------------------------------------------- */

it('lets two confirmed shoppers on the same IP both roll (shared-cafe scenario)', function () {
    $shop = makeShop();
    edgeForceWin($shop);
    fakeShopify([1 => ['price' => '600.00']]);
    $headers = ['Accept' => 'application/json', 'X-Forwarded-For' => '198.51.100.77'];

    edgeRoll($shop, ['email' => 'first@example.com'], $headers)->assertOk();
    $second = edgeRoll($shop, ['email' => 'second@example.com'], $headers)->assertOk()->json();

    expect($second['repeat'])->toBeFalse()
        ->and(Roll::count())->toBe(2);
});

/* -----------------------------------------------------------------------
 * Cross-channel dedupe (online + POS same person)
 * ----------------------------------------------------------------------- */

it('blocks an online roll from an email that has already rolled on POS today', function () {
    // Someone rolls at the till with their email; same email tries the storefront
    // widget later the same day: must be a repeat, no second code.
    $shop = makeShop();
    $hash = \App\Promotion\EmailHasher::hash('shared@example.com');
    Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => $hash,
        'promo_date' => now($shop->timezone)->toDateString(),
        'cart_subtotal' => 0, 'eligible_subtotal' => 0,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => 30000,
        'channel' => Roll::CHANNEL_POS,
        'code_status' => Roll::CODE_NONE,
    ]);
    fakeShopify([1 => ['price' => '600.00']]);

    $online = edgeRoll($shop, ['email' => 'shared@example.com'])->assertOk()->json();

    expect($online['repeat'])->toBeTrue()
        ->and($online['won'])->toBeTrue()
        ->and(Roll::count())->toBe(1);
});

/* -----------------------------------------------------------------------
 * Burst rate limiting
 * ----------------------------------------------------------------------- */

it('hits the 10-minute burst limit before the daily IP limit when rolls arrive quickly', function () {
    config(['shopify.roll.ip_rolls_per_10_minutes' => 2, 'shopify.roll.ip_rolls_per_day' => 10]);
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);
    $ip = ['X-Forwarded-For' => '198.51.100.33'];

    // Two rolls allowed, each from a different email so email-dedupe doesn't fire.
    edgeRoll($shop, ['email' => 'burst1@example.com'], $ip)->assertOk();
    edgeRoll($shop, ['email' => 'burst2@example.com'], $ip)->assertOk();

    // Third roll trips the burst limit even though the daily limit (10) is far away.
    edgeRoll($shop, ['email' => 'burst3@example.com'], $ip)
        ->assertStatus(429)
        ->assertJson(['error' => 'rate_limited']);
});

/* -----------------------------------------------------------------------
 * Preview token lifecycle
 * ----------------------------------------------------------------------- */

it('rejects an expired preview token the same way as no token at all', function () {
    $shop = makeShop(['enabled' => false]); // promotion off: only preview can roll
    $link = PreviewLinks::issue($shop, $shop->settings, 'win');
    fakeShopify([1 => ['price' => '600.00']]);

    // Expire the token in-place and refresh the model.
    $shop->settings->forceFill(['preview_expires_at' => now()->subMinutes(5)])->save();

    edgeRoll($shop, ['email' => 'tester@example.com', 'preview' => $link['token']])
        ->assertStatus(422)
        ->assertJson(['error' => 'closed']);
});

it('rejects a preview token that was issued for a different shop', function () {
    $shopA = makeShop();
    $shopB = makeShop();
    $link = PreviewLinks::issue($shopA, $shopA->settings, 'win');
    fakeShopify([1 => ['price' => '600.00']]);

    // Token should only be valid for shopA, never shopB.
    edgeRoll($shopB, ['email' => 'tester@example.com', 'preview' => $link['token']])
        ->assertOk(); // real promo IS running on shopB, so this is a NORMAL (not preview) roll
    expect(Roll::first()->is_test)->toBeFalse(); // not a test roll
});

/* -----------------------------------------------------------------------
 * Payout cap combinations: max_payout, max_payout_percent and remaining budget
 * all intersect on a single calculation. Each individual cap has its own test;
 * this covers the three-way interaction.
 * ----------------------------------------------------------------------- */

it('picks the lowest of max_payout, max_payout_percent and remaining budget when all three bind', function () {
    // max_payout = 50,000 (R500). max_payout_percent = 10% of 80,000 = 8,000.
    // remaining budget = 100,000 - 95,000 = 5,000. Lowest is budget: 5,000.
    $shop = makeShop([
        'max_payout' => 50000,
        'max_payout_percent' => 10,
        'daily_budget' => 100000,
    ]);
    edgeWinnerToday($shop, 95000);
    edgeForceWin($shop);
    fakeShopify([1 => ['price' => '800.00']]);

    $result = edgeRoll($shop, ['email' => 'combo@example.com'])->assertOk()->json();

    expect($result['won'])->toBeTrue()
        ->and($result['payout'])->toBe(5000);
});

/* -----------------------------------------------------------------------
 * Timezone / promo_date boundary
 * ----------------------------------------------------------------------- */

it('attaches a roll just before shop-local midnight to the current promo_date, and the next roll just after to the following one', function () {
    $shop = makeShop(); // Africa/Johannesburg, every day open 00:00-23:59
    fakeShopify([1 => ['price' => '600.00']]);

    // 23:50 on day D, shop time (safely inside the 00:00-23:59 window).
    test()->travelTo(CarbonImmutable::parse('2026-10-16 21:50:00', 'UTC')); // 23:50 Jo'burg
    $a = edgeRoll($shop, ['email' => 'tz1@example.com'])->assertOk()->json();

    // 00:05 on day D+1, shop time.
    test()->travelTo(CarbonImmutable::parse('2026-10-16 22:05:00', 'UTC')); // 00:05 Jo'burg next day
    $b = edgeRoll($shop, ['email' => 'tz2@example.com'])->assertOk()->json();

    expect($a['repeat'])->toBeFalse()
        ->and($b['repeat'])->toBeFalse();

    $rolls = Roll::orderBy('id')->get();
    expect($rolls)->toHaveCount(2)
        ->and($rolls[0]->promo_date->toDateString())->toBe('2026-10-16')
        ->and($rolls[1]->promo_date->toDateString())->toBe('2026-10-17');
});

/* -----------------------------------------------------------------------
 * Dice-count validation: picks outside the current pickRange are refused
 * ----------------------------------------------------------------------- */

it('refuses a 1-die pick of 11 even though the proxy layer accepts 1-12', function () {
    $shop = makeShop(['dice_count' => 1, 'odds_n' => 10]);
    fakeShopify([1 => ['price' => '600.00']]);

    edgeRoll($shop, ['email' => 'picker@example.com', 'picked_number' => 11])
        ->assertStatus(422)
        ->assertJson(['error' => 'currency']); // RollRejected::CURRENCY carries the "pick N-M" copy

    expect(Roll::count())->toBe(0);
});
