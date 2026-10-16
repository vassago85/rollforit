<?php

use App\Models\Roll;
use App\Models\Shop;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 06:00', 'Africa/Johannesburg'));
});

function fridayShop(array $settings = []): Shop
{
    return makeShop(array_merge(['promo_days' => [5], 'start_time' => '08:00', 'end_time' => '17:00'], $settings));
}

it('shows an upcoming countdown with the banner before the window opens', function () {
    $shop = fridayShop();

    $this->getJson('/api/proxy/status?'.proxyQuery($shop))
        ->assertOk()
        ->assertJson([
            'open' => false,
            'reason' => 'upcoming',
            'banner' => 'Spend R500, win up to R1,000 off. T&Cs apply.',
            'next_roll' => [
                'starts_at' => '2026-10-09T08:00:00+02:00',
                'starts_in' => 7200,
                'label' => 'today at 08:00',
            ],
        ]);
});

it('counts down to next week once today\'s window has closed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 17:30', 'Africa/Johannesburg'));

    $this->getJson('/api/proxy/status?'.proxyQuery(fridayShop()))
        ->assertJsonPath('reason', 'upcoming')
        ->assertJsonPath('next_roll.label', 'next Friday at 08:00');
});

it('gives an open promotion the following promo day for after the roll', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Africa/Johannesburg'));

    $this->getJson('/api/proxy/status?'.proxyQuery(fridayShop(['promo_days' => [1, 5]])))
        ->assertJsonPath('open', true)
        ->assertJsonPath('next_roll.label', 'Monday at 08:00');
});

it('points a sold-out day at the next promo day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Africa/Johannesburg'));
    $shop = fridayShop(['max_winners_per_day' => 1]);
    Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => bin2hex(random_bytes(32)),
        'promo_date' => '2026-10-09',
        'cart_subtotal' => 60000, 'eligible_subtotal' => 60000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => 60000, 'discount_code' => 'ROLL-SOLD',
        'code_status' => Roll::CODE_CREATED,
    ]);

    $this->getJson('/api/proxy/status?'.proxyQuery($shop))
        ->assertJsonPath('reason', 'sold_out')
        ->assertJsonPath('next_roll.label', 'next Friday at 08:00');
});

it('stays hidden when the countdown is switched off', function () {
    $this->getJson('/api/proxy/status?'.proxyQuery(fridayShop(['show_countdown' => false])))
        ->assertExactJson(['open' => false, 'reason' => 'closed']);
});

it('stays hidden when the promotion is disabled', function () {
    $this->getJson('/api/proxy/status?'.proxyQuery(fridayShop(['enabled' => false])))
        ->assertExactJson(['open' => false, 'reason' => 'closed']);
});

it('keeps surfacing winners + total paid on a sold-out day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Africa/Johannesburg'));
    $shop = fridayShop(['max_winners_per_day' => 1]);
    Roll::create([
        'shop_id' => $shop->id,
        'email_hash' => bin2hex(random_bytes(32)),
        'promo_date' => '2026-10-09',
        'cart_subtotal' => 60000, 'eligible_subtotal' => 60000,
        'picked_number' => 1, 'displayed_number' => 1,
        'won' => true, 'payout' => 60000, 'discount_code' => 'ROLL-SOLD',
        'code_status' => Roll::CODE_CREATED,
    ]);

    $this->getJson('/api/proxy/status?'.proxyQuery($shop))
        ->assertJsonPath('reason', 'sold_out')
        ->assertJsonPath('today_stats.winners', 1)
        ->assertJsonPath('today_stats.total_payout', 60000)
        ->assertJsonPath('today_stats.total_payout_formatted', 'R600');
});

it('still shows today winners after the window closes for the day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 17:30', 'Africa/Johannesburg'));
    $shop = fridayShop();
    foreach ([25000, 40000] as $payout) {
        Roll::create([
            'shop_id' => $shop->id,
            'email_hash' => bin2hex(random_bytes(32)),
            'promo_date' => '2026-10-09',
            'cart_subtotal' => 100000, 'eligible_subtotal' => 100000,
            'picked_number' => 1, 'displayed_number' => 1,
            'won' => true, 'payout' => $payout, 'discount_code' => 'ROLL-'.$payout,
            'code_status' => Roll::CODE_CREATED,
        ]);
    }

    $this->getJson('/api/proxy/status?'.proxyQuery($shop))
        ->assertJsonPath('reason', 'upcoming')
        ->assertJsonPath('today_stats.winners', 2)
        ->assertJsonPath('today_stats.total_payout', 65000);
});
