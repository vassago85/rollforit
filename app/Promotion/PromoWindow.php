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

        return in_array($local->dayOfWeekIso, array_map('intval', $this->settings->promo_days ?? []), true);
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
     * Start of the next promo window strictly after $at, looking up to a week ahead.
     * With $skipToday, today's window is ignored even if it hasn't started (used once today is spent).
     */
    public function nextOpening(?CarbonImmutable $at = null, bool $skipToday = false): ?CarbonImmutable
    {
        $at = ($at ?? $this->now())->setTimezone($this->timezone);

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
