<?php

use App\Models\Roll;

it('exposes the dice count and pick range on /status', function () {
    $shop = makeShop(['dice_count' => 2, 'odds_n' => 12]);

    $response = test()->getJson('/api/proxy/status?'.proxyQuery($shop))->assertOk()->json();

    expect($response['dice_count'])->toBe(2)
        ->and($response['pick_range'])->toBe(['min' => 2, 'max' => 12]);
});

it('accepts a two-dice pick in range (2-12)', function () {
    $shop = makeShop(['dice_count' => 2, 'odds_n' => 12]);
    fakeShopify([1 => ['price' => '600.00']]);

    $result = test()
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null, 'picked_number' => 12]))
        ->assertOk()
        ->json();

    expect($result['picked_number'])->toBe(12)
        ->and(Roll::first()->picked_number)->toBe(12);
});

it('rejects a pick outside the two-dice range', function () {
    $shop = makeShop(['dice_count' => 2, 'odds_n' => 12]);
    fakeShopify([1 => ['price' => '600.00']]);

    test()
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null, 'picked_number' => 1]))
        ->assertStatus(422)
        ->assertJson(['error' => 'currency']);

    expect(Roll::count())->toBe(0);
});

it('loses to a face inside the two-dice range when the roll misses', function () {
    $shop = makeShop(['dice_count' => 2, 'odds_n' => 12]);
    \App\Models\Block::create(['shop_id' => $shop->id, 'size' => 12, 'winning_position' => 12, 'next_position' => 1]);
    fakeShopify([1 => ['price' => '600.00']]);

    test()
        ->postJson('/api/proxy/roll?'.proxyQuery($shop, ['nonce' => bin2hex(random_bytes(4))]), rollPayload(['email' => null, 'picked_number' => 7]))
        ->assertOk();

    $roll = Roll::first();
    expect($roll->won)->toBeFalse()
        ->and($roll->displayed_number)->toBeGreaterThanOrEqual(2)
        ->and($roll->displayed_number)->toBeLessThanOrEqual(12)
        ->and($roll->displayed_number)->not->toBe(7);
});
