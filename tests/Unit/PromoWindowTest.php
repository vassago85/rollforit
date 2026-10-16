<?php

use App\Models\ShopSettings;
use App\Promotion\PromoWindow;
use App\Promotion\WidgetCopy;
use Carbon\CarbonImmutable;

const TZ = 'Africa/Johannesburg';

function fridayWindow(array $days = [5], ?string $start = '08:00', ?string $end = '17:00'): PromoWindow
{
    return new PromoWindow(new ShopSettings(['promo_days' => $days, 'start_time' => $start, 'end_time' => $end]), TZ);
}

function sast(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, TZ);
}

it('finds the next opening in shop time', function (array $days, string $now, bool $skipToday, ?string $expected) {
    $next = fridayWindow($days)->nextOpening(sast($now), $skipToday);

    expect($next?->format('Y-m-d H:i'))->toBe($expected);
})->with([
    'before today\'s window' => [[5], '2026-10-09 07:00', false, '2026-10-09 08:00'],
    'during today\'s window' => [[5], '2026-10-09 09:00', false, '2026-10-16 08:00'],
    'after today\'s window' => [[5], '2026-10-09 18:00', false, '2026-10-16 08:00'],
    'today spent before it opens' => [[5], '2026-10-09 07:00', true, '2026-10-16 08:00'],
    'wraps past Sunday' => [[1, 5], '2026-10-09 18:00', false, '2026-10-12 08:00'],
    'no promo days' => [[], '2026-10-09 07:00', false, null],
]);

it('has no next opening without times', function () {
    expect(fridayWindow([5], null, null)->nextOpening(sast('2026-10-09 07:00')))->toBeNull();
});

it('treats a UTC clock as shop time', function () {
    $next = fridayWindow()->nextOpening(CarbonImmutable::parse('2026-10-09 05:30', 'UTC'));

    expect($next->toIso8601String())->toBe('2026-10-09T08:00:00+02:00');
});

it('labels the next roll relative to today', function (string $next, string $label) {
    expect(WidgetCopy::nextRollLabel(sast($next), sast('2026-10-09 07:00')))->toBe($label);
})->with([
    ['2026-10-09 08:00', 'today at 08:00'],
    ['2026-10-10 08:00', 'tomorrow at 08:00'],
    ['2026-10-12 08:30', 'Monday at 08:30'],
    ['2026-10-16 08:00', 'next Friday at 08:00'],
]);

/* -----------------------------------------------------------------------
 * Dates mode: campaign-style schedule driven by a hand-picked list of
 * calendar dates. The weekly `promo_days` field is ignored in this mode.
 * ----------------------------------------------------------------------- */

function datesWindow(array $dates, ?string $start = '08:00', ?string $end = '17:00'): PromoWindow
{
    return new PromoWindow(new ShopSettings([
        'schedule_mode' => ShopSettings::SCHEDULE_DATES,
        'promo_dates' => $dates,
        'promo_days' => [1, 2, 3, 4, 5, 6, 7], // deliberately set to prove dates mode ignores this
        'start_time' => $start,
        'end_time' => $end,
    ]), TZ);
}

it('treats a listed date as a promo day in dates mode', function () {
    $window = datesWindow(['2026-11-30', '2026-12-24']);

    expect($window->isPromoDay(sast('2026-11-30 09:00')))->toBeTrue()
        ->and($window->isPromoDay(sast('2026-12-01 09:00')))->toBeFalse()
        ->and($window->isPromoDay(sast('2026-12-24 09:00')))->toBeTrue();
});

it('ignores weekly promo_days when the shop is in dates mode', function () {
    // Monday is in promo_days, but the dates list is the authority.
    $window = datesWindow(['2026-12-24']);

    expect($window->isPromoDay(sast('2026-10-12 09:00')))->toBeFalse(); // Monday
});

it('walks the dates list in calendar order for nextOpening', function () {
    // Deliberately unsorted input to prove sort() happens in the window.
    $window = datesWindow(['2026-12-24', '2026-11-30', '2027-01-02']);

    expect($window->nextOpening(sast('2026-10-16 09:00'))?->format('Y-m-d H:i'))
        ->toBe('2026-11-30 08:00');
});

it('skips past dates when finding nextOpening', function () {
    $window = datesWindow(['2026-01-01', '2026-11-30']);

    expect($window->nextOpening(sast('2026-10-16 09:00'))?->format('Y-m-d H:i'))
        ->toBe('2026-11-30 08:00');
});

it('returns today\'s window as nextOpening before it starts, or the next date once it has', function () {
    $window = datesWindow(['2026-11-30', '2026-12-24']);

    expect($window->nextOpening(sast('2026-11-30 07:00'))?->format('Y-m-d H:i'))->toBe('2026-11-30 08:00')
        ->and($window->nextOpening(sast('2026-11-30 09:00'))?->format('Y-m-d H:i'))->toBe('2026-12-24 08:00')
        ->and($window->nextOpening(sast('2026-11-30 07:00'), skipToday: true)?->format('Y-m-d H:i'))->toBe('2026-12-24 08:00');
});

it('returns null for nextOpening when every date is in the past', function () {
    $window = datesWindow(['2026-01-01', '2026-02-01']);

    expect($window->nextOpening(sast('2026-10-16 09:00')))->toBeNull();
});

it('returns null for nextOpening with no dates configured', function () {
    $window = datesWindow([]);

    expect($window->nextOpening(sast('2026-10-16 09:00')))->toBeNull();
});

it('finds the most recent past date for previousPromoDate', function () {
    $window = datesWindow(['2026-11-30', '2026-10-01', '2026-10-15']);

    expect($window->previousPromoDate(sast('2026-10-20 09:00')))->toBe('2026-10-15')
        ->and($window->previousPromoDate(sast('2026-10-01 09:00')))->toBeNull()
        ->and($window->previousPromoDate(sast('2027-01-01 09:00')))->toBe('2026-11-30');
});

it('isOpen in dates mode only when today is listed and the time is in window', function () {
    $window = datesWindow(['2026-11-30'], '08:00', '17:00');

    expect($window->isOpen(sast('2026-11-30 09:00')))->toBeTrue()
        ->and($window->isOpen(sast('2026-11-30 07:00')))->toBeFalse()
        ->and($window->isOpen(sast('2026-11-30 18:00')))->toBeFalse()
        ->and($window->isOpen(sast('2026-10-16 09:00')))->toBeFalse(); // not listed
});
