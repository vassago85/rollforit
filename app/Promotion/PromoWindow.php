<?php

namespace App\Promotion;

use App\Models\ShopSettings;
use Carbon\CarbonImmutable;

/**
 * Promo-day and time-window rules, evaluated in the shop's IANA timezone.
 */
class PromoWindow
{
    public const DAY_NAMES = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
    ];

    public function __construct(
        private readonly ShopSettings $settings,
        private readonly string $timezone,
    ) {}

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function promoDate(?CarbonImmutable $at = null): string
    {
        return ($at ?? $this->now())->setTimezone($this->timezone)->toDateString();
    }

    public function isPromoDay(?CarbonImmutable $at = null): bool
    {
        $local = ($at ?? $this->now())->setTimezone($this->timezone);

        if ($this->settings->isDatesSchedule()) {
            return in_array($local->toDateString(), $this->promoDates(), true);
        }

        return in_array($local->dayOfWeekIso, array_map('intval', $this->settings->promo_days ?? []), true);
    }

    /**
     * Sorted, deduped list of configured calendar dates (ISO yyyy-mm-dd).
     * Only meaningful when the shop is in dates mode; weekly shops return [].
     *
     * @return list<string>
     */
    public function promoDates(): array
    {
        if (! $this->settings->isDatesSchedule()) {
            return [];
        }

        $dates = array_values(array_filter(array_map(
            fn ($d) => is_string($d) ? trim($d) : null,
            $this->settings->promo_dates ?? [],
        )));
        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function windowFor(string $date): ?array
    {
        if (! $this->settings->start_time || ! $this->settings->end_time) {
            return null;
        }

        $start = CarbonImmutable::parse("{$date} {$this->settings->start_time}", $this->timezone);
        $end = CarbonImmutable::parse("{$date} {$this->settings->end_time}", $this->timezone);

        return $end->greaterThan($start) ? [$start, $end] : null;
    }

    public function isOpen(?CarbonImmutable $at = null): bool
    {
        $at = ($at ?? $this->now())->setTimezone($this->timezone);
        if (! $this->isPromoDay($at)) {
            return false;
        }

        $window = $this->windowFor($at->toDateString());

        return $window !== null && $at->greaterThanOrEqualTo($window[0]) && $at->lessThan($window[1]);
    }

    /**
     * Start of the next promo window strictly after $at. Weekly mode scans up
     * to a week ahead; dates mode walks the merchant's configured list in
     * order (so a shop can be dark for six months and we'll still find the
     * next event). With $skipToday, today's window is ignored even if it has
     * not started yet — used once today's budget / cap is spent.
     */
    public function nextOpening(?CarbonImmutable $at = null, bool $skipToday = false): ?CarbonImmutable
    {
        $at = ($at ?? $this->now())->setTimezone($this->timezone);

        if ($this->settings->isDatesSchedule()) {
            $today = $at->toDateString();
            foreach ($this->promoDates() as $date) {
                if ($date < $today) {
                    continue;
                }
                if ($skipToday && $date === $today) {
                    continue;
                }
                $window = $this->windowFor($date);
                if ($window === null) {
                    return null;
                }
                if ($window[0]->greaterThan($at)) {
                    return $window[0];
                }
            }

            return null;
        }

        for ($offset = $skipToday ? 1 : 0; $offset <= 7; $offset++) {
            $day = $at->startOfDay()->addDays($offset);
            if (! $this->isPromoDay($day)) {
                continue;
            }

            $window = $this->windowFor($day->toDateString());
            if ($window === null) {
                return null;
            }
            if ($window[0]->greaterThan($at)) {
                return $window[0];
            }
        }

        return null;
    }

    public function endOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->endOfDay();
    }

    /**
     * The previous promo day's date (looking back up to 7 days), or null if the
     * shop has no promo days configured. "Previous" means strictly before today
     * in the shop's timezone, even if today itself is a promo day.
     */
    public function previousPromoDate(?CarbonImmutable $at = null): ?string
    {
        $at = ($at ?? $this->now())->setTimezone($this->timezone);

        if ($this->settings->isDatesSchedule()) {
            $today = $at->toDateString();
            // Dates list is sorted ascending; walk from the end for the most recent past date.
            foreach (array_reverse($this->promoDates()) as $date) {
                if ($date < $today) {
                    return $date;
                }
            }

            return null;
        }

        $days = array_map('intval', $this->settings->promo_days ?? []);
        if ($days === []) {
            return null;
        }

        for ($offset = 1; $offset <= 7; $offset++) {
            $day = $at->startOfDay()->subDays($offset);
            if (in_array($day->dayOfWeekIso, $days, true)) {
                return $day->toDateString();
            }
        }

        return null;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * @param  array<int, int|string>  $days
     * @return list<string>
     */
    public static function dayNames(array $days): array
    {
        $days = array_map('intval', $days);
        sort($days);

        return array_values(array_filter(array_map(fn ($d) => self::DAY_NAMES[$d] ?? null, $days)));
    }
}
