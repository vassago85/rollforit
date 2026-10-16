<?php

use App\Promotion\TermsService;

it('renders a single-die range by default', function () {
    $shop = makeShop();
    $html = app(TermsService::class)->render($shop, $shop->settings);

    expect($html)->toContain('pick a number from 1 to 10 and roll the die')
        ->and($html)->toContain("The in-store die is run by the promoter's server")
        ->not->toContain('the dice are');
});

it('rewrites the terms for two-dice mode', function () {
    $shop = makeShop(['dice_count' => 2, 'odds_n' => 12]);
    $html = app(TermsService::class)->render($shop, $shop->settings);

    expect($html)->toContain('pick a sum from 2 to 12 and roll the dice')
        ->and($html)->toContain("The in-store dice are run by the promoter's server")
        ->not->toContain('from 1 to 10');
});

it('bumps the terms-page content hash when dice count flips', function () {
    $shop = makeShop();
    $one = app(TermsService::class)->render($shop, $shop->settings);

    $shop->settings->forceFill(['dice_count' => 2, 'odds_n' => 12])->save();
    $two = app(TermsService::class)->render($shop, $shop->settings->fresh());

    expect(hash('sha256', $one))->not->toBe(hash('sha256', $two));
});
