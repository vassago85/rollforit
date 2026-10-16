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

it('mentions the basket percentage cap in every advert when it is set', function () {
    $blocks = collect(adverts(['max_payout_percent' => 50])['blocks'])->keyBy('key');

    foreach ($blocks as $block) {
        expect($block['error'])->toBeNull()
            ->and($block['text'])->toContain('up to 50% of your cart (max R1,000)');
    }

    expect($blocks['short_social']['text'])->toBe(
        'Roll4It this Friday. Spend R500 or more at McTactical, roll the die at checkout, and win up to 50% of your cart (max R1,000) off your order. '
        .'One in every 10 rolls wins. One roll per customer per day. T&Cs: https://shop.example.co.za/pages/roll-for-it-terms'
    )->and($blocks['email']['text'])->toStartWith('Subject: Roll the die this Friday, win up to 50% of your cart (max R1,000) off')
        ->and($blocks['banner']['text'])->toStartWith('Fridays only: spend R500, roll the die, win up to 50% of your cart (max R1,000) off. T&Cs apply.')
        ->and($blocks['whatsapp']['text'])->toContain('win up to 50% of your cart (max R1,000) off');
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

    expect($changes->keys()->all())->toBe(['Maximum payout', 'Prize', 'Odds'])
        ->and($changes['Maximum payout'])->toMatchArray(['from' => 'R1,000', 'to' => 'R750'])
        ->and($changes['Prize'])->toMatchArray(['from' => 'up to R1,000', 'to' => 'up to R750']);
});

it('rewrites every advert for two-dice mode', function () {
    $blocks = collect(adverts(['dice_count' => 2, 'odds_n' => 12])['blocks'])->keyBy('key');

    foreach ($blocks as $block) {
        expect($block['error'])->toBeNull()
            ->and($block['text'])->toContain('the dice')
            ->and($block['text'])->not->toContain('the die')
            // Odds sentence always mentions the (odds_n = 12) denominator in two-dice mode.
            ->and($block['text'])->toMatch('/(One in every 12 rolls wins|1 in 12 rolls wins|1 in 12 chance)/');
    }

    expect($blocks['email']['text'])->toContain('pick a sum from 2 to 12 on the cart page')
        ->and($blocks['short_social']['text'])->toContain('roll the dice at checkout')
        ->and($blocks['banner']['text'])->toContain('roll the dice, win');
});

it('flags a dice-count change in the adverts changes list', function () {
    $shop = makeShop(['promo_days' => [5]]);
    $service = app(AdvertCopyService::class);
    $service->acknowledge($shop, $shop->settings);

    $shop->settings->forceFill(['dice_count' => 2, 'odds_n' => 12])->save();
    $changes = collect($service->changesSinceAck($shop, $shop->settings->fresh()))->keyBy('field');

    expect($changes->keys()->all())->toContain('Dice')
        ->and($changes['Dice'])->toMatchArray([
            'from' => 'the die (pick a number from 1 to 10)',
            'to' => 'the dice (pick a sum from 2 to 12)',
        ]);
});
