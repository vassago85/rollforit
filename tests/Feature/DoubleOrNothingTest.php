<?php

use App\Models\Block;
use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Promotion\Draw;
use App\Promotion\TermsService;
use App\Shopify\PosSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

function doubleShop(array $settings = []): \App\Models\Shop
{
    return makeShop(array_merge([
        'game' => ShopSettings::GAME_DOUBLE,
        'odds_mode' => ShopSettings::MODE_CHANCE,
        'odds_n' => 6,
        'base_percent' => 5,
        'double_percent' => 10,
    ], $settings));
}

it('only accepts a pick inside the merchant odds and wins a random game on a match', function () {
    $shop = doubleShop();
    fakeShopify([1 => ['price' => '600.00']]);
    $this->mock(Draw::class, function ($mock) {
        $mock->shouldReceive('winningNumber')->andReturn(4);
    });

    roll($shop, ['picked_number' => 7])->assertStatus(422);

    $miss = roll($shop, ['picked_number' => 1])->assertOk()->json();
    expect($miss['won'])->toBeFalse()
        ->and($miss['displayed_number'])->toBe(4)
        ->and($miss['decision'])->toBeNull()
        ->and(Roll::count())->toBe(1);
});

it('pays the base percent on a match and waits before issuing a code', function () {
    $shop = doubleShop();
    fakeShopify([1 => ['price' => '600.00']]);
    $this->mock(Draw::class, function ($mock) {
        $mock->shouldReceive('winningNumber')->once()->andReturn(4);
    });

    $hit = roll($shop, ['picked_number' => 4])->assertOk()->json();

    expect($hit['won'])->toBeTrue()
        ->and($hit['decision'])->toBe(Roll::DECISION_PENDING)
        ->and($hit['offer_double'])->toBeTrue()
        ->and($hit['payout'])->toBe(3000)
        ->and($hit['double_payout'])->toBe(6000)
        ->and($hit['code'])->toBeNull()
        ->and(Roll::first()->code_status)->toBe(Roll::CODE_NONE);
});

it('issues the base percent when they take it', function () {
    Mail::fake();
    $shop = doubleShop(['odds_mode' => ShopSettings::MODE_GUARANTEED, 'odds_n' => 10]);
    fakeShopify([1 => ['price' => '600.00']]);
    forceNextWin($shop);

    roll($shop)->assertOk()->assertJsonPath('decision', Roll::DECISION_PENDING);

    $taken = test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/take?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), [
            'email' => 'customer@example.com',
        ])
        ->assertOk()
        ->json();

    expect($taken['decision'])->toBe(Roll::DECISION_TAKEN)
        ->and($taken['won'])->toBeTrue()
        ->and($taken['payout'])->toBe(3000)
        ->and($taken['code'])->not->toBeNull()
        ->and(Roll::first()->code_status)->toBe(Roll::CODE_CREATED);
});

it('raises the prize on a winning risk and does not take another block slot', function () {
    Mail::fake();
    $shop = doubleShop(['odds_mode' => ShopSettings::MODE_GUARANTEED, 'odds_n' => 10]);
    fakeShopify([1 => ['price' => '600.00']]);
    forceNextWin($shop);
    $this->mock(Draw::class, function ($mock) {
        $mock->shouldReceive('doubles')->once()->andReturn(true);
    });

    roll($shop)->assertOk();
    expect(Block::first()->next_position)->toBe(2);

    $doubled = test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/bet?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), [
            'email' => 'customer@example.com',
        ])
        ->assertOk()
        ->json();

    expect($doubled['decision'])->toBe(Roll::DECISION_DOUBLED)
        ->and($doubled['won'])->toBeTrue()
        ->and($doubled['payout'])->toBe(6000)
        ->and($doubled['code'])->not->toBeNull()
        ->and(Block::count())->toBe(1)
        ->and(Block::first()->next_position)->toBe(2);
});

it('clears the win and releases the budget when the risk misses', function () {
    Mail::fake();
    $shop = doubleShop([
        'odds_mode' => ShopSettings::MODE_GUARANTEED,
        'odds_n' => 10,
        'daily_budget' => 20000,
        'max_winners_per_day' => null,
    ]);
    fakeShopify([1 => ['price' => '600.00']]);
    forceNextWin($shop);
    $this->mock(Draw::class, function ($mock) {
        $mock->shouldReceive('doubles')->once()->andReturn(false);
    });

    roll($shop)->assertOk();

    $bust = test()->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/proxy/bet?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), [
            'email' => 'customer@example.com',
        ])
        ->assertOk()
        ->json();

    expect($bust['decision'])->toBe(Roll::DECISION_BUSTED)
        ->and($bust['won'])->toBeFalse()
        ->and($bust['payout'])->toBe(0)
        ->and($bust['code'])->toBeNull();

    $roll = Roll::first();
    expect($roll->won)->toBeFalse()->and($roll->payout)->toBe(0);
});

it('issues the base prize immediately when the budget cannot cover the higher percent', function () {
    Mail::fake();
    $shop = doubleShop([
        'odds_mode' => ShopSettings::MODE_GUARANTEED,
        'odds_n' => 10,
        'daily_budget' => 3000,
        'max_winners_per_day' => null,
    ]);
    fakeShopify([1 => ['price' => '600.00']]);
    forceNextWin($shop);

    $result = roll($shop)->assertOk()->json();

    expect($result['decision'])->toBe(Roll::DECISION_TAKEN)
        ->and($result['offer_double'])->toBeFalse()
        ->and($result['payout'])->toBe(3000)
        ->and($result['code'])->not->toBeNull();
});

it('keeps the base prize when the choice window expires', function () {
    Mail::fake();
    $shop = doubleShop(['odds_mode' => ShopSettings::MODE_GUARANTEED, 'odds_n' => 10]);
    fakeShopify([1 => ['price' => '600.00']]);
    forceNextWin($shop);

    roll($shop)->assertOk()->assertJsonPath('decision', Roll::DECISION_PENDING);

    Carbon::setTestNow(now()->addMinutes(11));
    try {
        $again = roll($shop, ['picked_number' => 2])->assertOk()->json();
    } finally {
        Carbon::setTestNow();
    }

    expect($again['repeat'])->toBeTrue()
        ->and($again['decision'])->toBe(Roll::DECISION_TAKEN)
        ->and($again['payout'])->toBe(3000)
        ->and($again['code'])->not->toBeNull()
        ->and(Roll::count())->toBe(1);
});

it('leaves dice mode on a single finished roll', function () {
    Mail::fake();
    $shop = makeShop();
    fakeShopify([1 => ['price' => '600.00']]);
    forceNextWin($shop);

    $result = roll($shop)->assertOk()->json();

    expect($result['decision'])->toBeNull()
        ->and($result['won'])->toBeTrue()
        ->and($result['code'])->not->toBeNull()
        ->and($result['payout'])->toBe(60000);
});

it('settles an in-store win at the base percent with no pending choice', function () {
    Mail::fake();
    $shop = doubleShop();
    $shop->settings->forceFill([
        'pos_enabled' => true,
        'pos_secret' => 'test-pos-secret',
        'pos_secret_last_four' => 'cret',
        'pos_secret_rotated_at' => now(),
    ])->save();
    $shop = $shop->fresh();
    $this->mock(Draw::class, function ($mock) {
        $mock->shouldReceive('winningNumber')->once()->andReturn(4);
    });

    $result = doublePosRoll($shop, [
        'phone' => '082 123 4567',
        'picked_number' => 4,
        'subtotal_cents' => 120000,
    ])->assertOk()->json();

    expect($result['won'])->toBeTrue()
        ->and($result['decision'])->toBe(Roll::DECISION_TAKEN)
        ->and($result['offer_double'])->toBeFalse()
        ->and($result['payout'])->toBe(6000)
        ->and($result['code'])->toBeNull();
});

it('describes the pick and the second chance in the terms', function () {
    $shop = doubleShop(['odds_mode' => ShopSettings::MODE_CHANCE, 'odds_n' => 6]);
    $html = app(TermsService::class)->render($shop, $shop->settings);

    expect($html)->toContain('pick 1 of 6')
        ->and($html)->toContain('You win the first prize only if you pick it.')
        ->and($html)->toContain('a miss cancels the prize')
        ->and($html)->toContain('within 10 minutes');
});

it('saves the game and rejects a risk prize that is not higher', function () {
    $shop = makeShop(['enabled' => false]);
    fakeShopify();

    $form = [
        'enabled' => false,
        'odds_mode' => 'guaranteed',
        'odds_n' => '10',
        'promo_days' => [5],
        'start_time' => '08:00',
        'end_time' => '20:00',
        'min_order' => '500',
        'max_payout' => '1000',
        'daily_budget' => null,
        'max_winners_per_day' => '50',
        'excluded_collections' => [],
        'die_color' => '#1f2937',
        'die_number_color' => '#ffffff',
        'accent_color' => '#f59e0b',
        'roll_duration_ms' => '2500',
        'sound_enabled' => true,
        'celebration_enabled' => true,
        'promoter_name' => 'McTactical (Pty) Ltd',
        'promoter_address' => '1 Test Street, Pretoria',
        'promoter_email' => 'promo@example.com',
    ];

    admin($shop)->putJson('/api/admin/settings', $form + [
        'game' => 'double',
        'base_percent' => 5,
        'double_percent' => 10,
    ])->assertOk()
        ->assertJsonPath('form.game', 'double')
        ->assertJsonPath('form.base_percent', 5)
        ->assertJsonPath('form.double_percent', 10);

    admin($shop)->putJson('/api/admin/settings', $form + [
        'game' => 'double',
        'base_percent' => 10,
        'double_percent' => 10,
    ])->assertStatus(422);
});

function doublePosRoll(Shop $shop, array $body): \Illuminate\Testing\TestResponse
{
    $json = json_encode($body) ?: '{}';
    $timestamp = (string) time();
    $nonce = 'nonce-'.bin2hex(random_bytes(6));
    $secret = (string) $shop->settings->pos_secret;
    $headers = [
        'X-RFI-Shop' => $shop->domain,
        'X-RFI-Timestamp' => $timestamp,
        'X-RFI-Nonce' => $nonce,
        'X-RFI-Signature' => PosSignature::compute($timestamp, $nonce, $json, $secret),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    return test()->call('POST', '/api/pos/roll', [], [], [], array_combine(
        array_map(fn ($k) => 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)),
        array_values($headers),
    ) + ['CONTENT_TYPE' => 'application/json'], $json);
}
