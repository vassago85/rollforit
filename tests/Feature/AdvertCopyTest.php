<?php

use App\Models\ShopSettings;
use App\Promotion\AdvertCopyService;

function adverts(array $settings = []): array
{
    $shop = makeShop(array_merge(['promo_days' => [5], 'start_time' => '08:00', 'end_time' => '20:00'], $settings));

    return app(AdvertCopyService::class)->generate($shop, $shop->settings);
}

it('includes min_order, max_payout, the terms URL and every required element in each block', function (string $mode) {
    $copy = adverts(['odds_mode' => $mode]);

    expect($copy['blocks'])->toHaveCount(6);
    foreach ($copy['blocks'] as $block) {
        expect($block['error'])->toBeNull()
            ->and($block['text'])
            ->toContain('R500')
            ->toContain('R1,000')
            ->toContain('https://shop.example.co.za/pages/roll-for-it-terms')
            ->toContain('Friday')
            ->toContain('One roll per customer per day');
    }
})->with([ShopSettings::MODE_GUARANTEED, ShopSettings::MODE_CHANCE]);

it('uses the guaranteed odds sentence in guaranteed mode', function () {
    foreach (adverts()['blocks'] as $block) {
        expect($block['text'])->toMatch('/(One in every 10 rolls wins|1 in 10 rolls wins)/')
            ->and($block['text'])->not->toContain('chance to win');
    }
});

it('swaps the odds sentence in chance mode', function () {
    foreach (adverts(['odds_mode' => ShopSettings::MODE_CHANCE])['blocks'] as $block) {
        expect($block['text'])->toContain('ach roll has a 1 in 10 chance to win')
            ->and($block['text'])->not->toContain('One in every')
            ->and($block['text'])->not->toContain('1 in 10 rolls wins');
    }
});

it('matches the spec templates word for word where they are complete', function () {
    $blocks = collect(adverts()['blocks'])->keyBy('key');

    expect($blocks['short_social']['text'])->toBe(
        'Roll4It this Friday. Spend R500 or more at McTactical, roll the die at checkout, and win up to R1,000 off your order. '
        .'One in every 10 rolls wins. One roll per customer per day. T&Cs: https://shop.example.co.za/pages/roll-for-it-terms'
    )->and($blocks['email']['text'])->toStartWith('Subject: Roll the die this Friday, win up to R1,000 off')
        ->and($blocks['long_social']['text'])->toContain('Valid Fridays from 08:00 to 20:00.')
        ->and($blocks['banner']['text'])->toStartWith('Fridays only: spend R500, roll the die, win up to R1,000 off. T&Cs apply.');
});

it('shows an error instead of copy when a value is missing', function (array $settings, string $label) {
    foreach (adverts($settings)['blocks'] as $block) {
        expect($block['text'])->toBeNull()->and($block['error'])->toContain($label);
    }
})->with([
    'no max payout' => [['max_payout' => null], 'Maximum payout'],
    'no min order' => [['min_order' => null], 'Minimum order value'],
    'no terms' => [['terms_published_at' => null], 'Published terms page'],
    'no promo days' => [['promo_days' => []], 'Promo days'],
]);

it('never says free without "up to R{max_payout}" in the same sentence', function () {
    $service = app(AdvertCopyService::class);
    $values = ['max_payout' => 'R1,000'];

    expect($service->freeViolations('Your order could be free! Spend R500.', $values))->not->toBeEmpty()
        ->and($service->freeViolations('Get a free order worth up to R1,000 off.', $values))->toBeEmpty();

    foreach (adverts()['blocks'] as $block) {
        expect($service->freeViolations($block['text'], $values))->toBeEmpty();
    }
});

it('lists what changed since the adverts were acknowledged', function () {
    $shop = makeShop(['promo_days' => [5]]);
    $service = app(AdvertCopyService::class);
    $service->acknowledge($shop, $shop->settings);

    expect($service->changesSinceAck($shop, $shop->settings))->toBe([]);

    $shop->settings->forceFill(['max_payout' => 75000, 'odds_mode' => ShopSettings::MODE_CHANCE])->save();
    $changes = collect($service->changesSinceAck($shop, $shop->settings->fresh()))->keyBy('field');

    expect($changes->keys()->all())->toBe(['Maximum payout', 'Odds'])
        ->and($changes['Maximum payout'])->toMatchArray(['from' => 'R1,000', 'to' => 'R750']);
});
