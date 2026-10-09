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
