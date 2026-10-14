<?php

use App\Models\Roll;

it('requires admin authentication', function () {
    $shop = makeShop();

    $this->getJson('/api/admin/simulator')->assertStatus(401);
    $this->postJson('/api/admin/simulator/roll', [
        'cart_value' => '500', 'outcome' => 'win', 'picked_number' => 7, 'channel' => 'online',
    ])->assertStatus(401);
});

it('runs a forced-win simulation against the real caps without writing a row', function () {
    $shop = makeShop(['max_payout_percent' => 40]);

    $response = admin($shop)->postJson('/api/admin/simulator/roll', [
        'cart_value' => '500', 'outcome' => 'win', 'picked_number' => 7, 'channel' => 'online',
    ])->assertOk();

    expect($response->json('result.won'))->toBeTrue()
        ->and($response->json('result.payout'))->toBe(20000)
        ->and($response->json('result.simulated'))->toBeTrue()
        ->and($response->json('result.test'))->toBeTrue()
        ->and($response->json('result.code'))->toStartWith('SIM-')
        ->and($response->json('result.displayed_number'))->toBe(7)
        ->and($response->json('diagnostics.binding_cap'))->toBe('percent')
        ->and($response->json('diagnostics.percent_cap'))->toBe(20000)
        ->and(Roll::count())->toBe(0);
});

it('respects max_payout when the percent cap is more generous', function () {
    $shop = makeShop(); // max_payout = R1,000, no percent cap

    $response = admin($shop)->postJson('/api/admin/simulator/roll', [
        'cart_value' => '5000', 'outcome' => 'win', 'picked_number' => 1, 'channel' => 'online',
    ])->assertOk();

    expect($response->json('result.payout'))->toBe(100000)
        ->and($response->json('diagnostics.binding_cap'))->toBe('max_payout')
        ->and(Roll::count())->toBe(0);
});

it('runs a forced loss with no payout and no code', function () {
    $shop = makeShop();

    $response = admin($shop)->postJson('/api/admin/simulator/roll', [
        'cart_value' => '800', 'outcome' => 'loss', 'picked_number' => 3, 'channel' => 'online',
    ])->assertOk();

    expect($response->json('result.won'))->toBeFalse()
        ->and($response->json('result.payout'))->toBe(0)
        ->and($response->json('result.code'))->toBeNull()
        ->and($response->json('result.displayed_number'))->not->toBe(3)
        ->and($response->json('diagnostics.binding_cap'))->toBe('none')
        ->and(Roll::count())->toBe(0);
});

it('rejects a cart below the minimum order with the same error the storefront would see', function () {
    $shop = makeShop(); // min_order = R500

    admin($shop)->postJson('/api/admin/simulator/roll', [
        'cart_value' => '100', 'outcome' => 'win', 'picked_number' => 7, 'channel' => 'online',
    ])->assertStatus(422)->assertJson(['error' => 'below_min']);

    expect(Roll::count())->toBe(0);
});

it('never creates a Shopify discount code even on a simulated win', function () {
    $shop = makeShop();
    $calls = fakeShopify();

    admin($shop)->postJson('/api/admin/simulator/roll', [
        'cart_value' => '800', 'outcome' => 'win', 'picked_number' => 1, 'channel' => 'online',
    ])->assertOk();

    expect(collect($calls)->pluck('operation'))->not->toContain('CreateRollCode');
});

it('simulates a POS roll with the POS win copy and no code', function () {
    $shop = makeShop();

    $response = admin($shop)->postJson('/api/admin/simulator/roll', [
        'cart_value' => '800', 'outcome' => 'win', 'picked_number' => 7, 'channel' => 'pos',
    ])->assertOk();

    expect($response->json('result.won'))->toBeTrue()
        ->and($response->json('result.code'))->toBeNull()
        ->and($response->json('result.channel'))->toBe('pos')
        ->and($response->json('result.message'))->toContain('invoice');
});

it('exposes the current caps and warnings via the show endpoint', function () {
    $shop = makeShop(['max_payout_percent' => 40]);

    $response = admin($shop)->getJson('/api/admin/simulator')->assertOk();

    expect($response->json('settings.max_payout_percent'))->toBe(40)
        ->and($response->json('settings.min_order'))->toBe(50000)
        ->and($response->json('settings.max_payout'))->toBe(100000)
        ->and($response->json('shop.symbol'))->toBe('R');
});
