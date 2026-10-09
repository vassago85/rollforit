<?php

use App\Models\PromoDaySummary;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\EmailHasher;
use App\Promotion\PromoMetricsService;
use Carbon\CarbonImmutable;

function order(string $total, array $extra = []): array
{
    return array_merge([
        'id' => 'gid://shopify/Order/'.random_int(1, 999999),
        'email' => 'someone@example.com',
        'test' => false,
        'cancelledAt' => null,
        'discountCodes' => [],
        'currentTotalPriceSet' => ['shopMoney' => ['amount' => $total]],
        'currentSubtotalPriceSet' => ['shopMoney' => ['amount' => $total]],
        'currentTotalDiscountsSet' => ['shopMoney' => ['amount' => '0.00']],
    ], $extra);
}

function dayRoll(Shop $shop, string $date, string $email, bool $won = false, int $payout = 0, ?string $code = null): Roll
{
    return Roll::create([
        'shop_id' => $shop->id, 'email_hash' => EmailHasher::hash($email), 'promo_date' => $date,
        'cart_subtotal' => 60000, 'eligible_subtotal' => 60000, 'picked_number' => 3, 'displayed_number' => $won ? 3 : 4,
        'won' => $won, 'payout' => $payout, 'discount_code' => $code,
        'code_status' => $won ? Roll::CODE_CREATED : Roll::CODE_NONE,
    ]);
}

it('computes revenue vs prizes, excluding test and cancelled orders', function () {
    $shop = makeShop(['start_time' => '08:00', 'end_time' => '20:00']);
    $date = '2026-10-02';
    dayRoll($shop, $date, 'winner@example.com', true, 80000, 'ROLL-WIN12345');
    dayRoll($shop, $date, 'loser@example.com');
    $calls = fakeShopify([], [
        order('1200.00', ['email' => 'Winner@Example.com', 'discountCodes' => ['roll-win12345'],
            'currentTotalDiscountsSet' => ['shopMoney' => ['amount' => '1000.00']]]),
        order('700.00', ['email' => 'loser@example.com']),
        order('300.00'),
        order('5000.00', ['test' => true]),
        order('9000.00', ['cancelledAt' => '2026-10-02T12:00:00Z']),
    ]);

    $m = app(PromoMetricsService::class)->compute($shop, $shop->settings, $date);

    expect($m['orders_count'])->toBe(3)
        ->and($m['revenue'])->toBe(220000)
        ->and($m['roller_orders_count'])->toBe(2)
        ->and($m['roller_revenue'])->toBe(190000)
        ->and($m['winner_orders_count'])->toBe(1)
        ->and($m['winner_revenue'])->toBe(120000)
        ->and($m['rolls_count'])->toBe(2)
        ->and($m['winners_count'])->toBe(1)
        ->and($m['prizes_issued'])->toBe(80000)
        ->and($m['prizes_redeemed'])->toBe(80000);

    $query = collect($calls)->firstWhere('operation', 'PromoOrders')['variables']['query'];
    expect($query)->toBe("created_at:>='2026-10-02T06:00:00Z' AND created_at:<'2026-10-02T22:00:00Z'");
});

it('never counts more redeemed than the stored payout', function () {
    $shop = makeShop();
    dayRoll($shop, '2026-10-02', 'w@example.com', true, 50000, 'ROLL-AAAA2222');
    fakeShopify([], [order('900.00', ['discountCodes' => ['ROLL-AAAA2222'],
        'currentTotalDiscountsSet' => ['shopMoney' => ['amount' => '650.00']]])]);

    $m = app(PromoMetricsService::class)->compute($shop, $shop->settings, '2026-10-02');

    expect($m['prizes_redeemed'])->toBe(50000);
});

it('snapshots each closed promo day once and Recompute overwrites it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00', 'Africa/Johannesburg'));
    $shop = makeShop(['promo_days' => [5]]);
    $service = app(PromoMetricsService::class);
    dayRoll($shop, '2026-10-09', 'a@example.com');
    $orders = [order('500.00')];
    fakeShopify([], [], ['PromoOrders' => function () use (&$orders) {
        return ['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => $orders]]];
    }]);

    expect($service->datesDueForSnapshot($shop))->toBe(['2026-10-09']);

    $this->artisan('rfi:snapshot-promo-days')->assertSuccessful();
    expect(PromoDaySummary::count())->toBe(1)
        ->and(PromoDaySummary::first()->revenue)->toBe(50000)
        ->and($service->datesDueForSnapshot($shop))->toBe([]);

    $this->artisan('rfi:snapshot-promo-days')->assertSuccessful();
    expect(PromoDaySummary::count())->toBe(1);

    $orders[] = order('250.00');
    $this->withHeaders(['Authorization' => 'Bearer '.idToken($shop)])
        ->postJson('/api/admin/reporting/recompute', ['date' => '2026-10-09'])->assertOk();

    $summary = PromoDaySummary::where('promo_date', '2026-10-09')->first();
    expect($summary->revenue)->toBe(75000)->and($summary->is_final)->toBeTrue();
});

it('waits 30 minutes after the promo day ends before snapshotting', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 00:20', 'Africa/Johannesburg'));
    $shop = makeShop(['promo_days' => [5]]);

    expect(app(PromoMetricsService::class)->datesDueForSnapshot($shop))->not->toContain('2026-10-09');
});

it('derives prizes as a share of revenue and average order values', function () {
    $shop = makeShop();
    $d = app(PromoMetricsService::class)->withDerived([
        'orders_count' => 10, 'revenue' => 1_000_000, 'merch_revenue' => 900_000,
        'roller_orders_count' => 4, 'roller_revenue' => 600_000,
        'winner_orders_count' => 1, 'winner_revenue' => 150_000,
        'winners_count' => 2, 'prizes_issued' => 200_000, 'prizes_redeemed' => 100_000,
    ], $shop);

    expect($d['prizes_pct_of_revenue'])->toBe(10.0)
        ->and($d['revenue_after_prizes'])->toBe(900_000)
        ->and($d['revenue_per_prize_rand'])->toBe(10.0)
        ->and($d['redemption_rate'])->toBe(50.0)
        ->and($d['aov_rollers'])->toBe(150_000)
        ->and($d['aov_others'])->toBe(66_666);
});

it('exports campaign totals as CSV', function () {
    $shop = makeShop();
    PromoDaySummary::create(['shop_id' => $shop->id, 'promo_date' => '2026-10-02', 'revenue' => 100000,
        'period_start' => '2026-10-02 06:00:00', 'period_end' => '2026-10-02 22:00:00',
        'prizes_issued' => 20000, 'prizes_redeemed' => 10000, 'is_final' => true, 'computed_at' => now()]);

    $csv = $this->withHeaders(['Authorization' => 'Bearer '.idToken($shop)])
        ->get('/api/admin/reporting/export')->assertOk()->streamedContent();

    expect($csv)->toContain('promo_date,orders_count,revenue')
        ->toContain('2026-10-02,0,1000.00')
        ->toContain('TOTAL');
});
