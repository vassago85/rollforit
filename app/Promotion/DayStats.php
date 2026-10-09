<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\ShopSettings;

/**
 * Non-test roll totals for one shop on one promo date.
 */
final class DayStats
{
    public function __construct(
        public readonly int $rolls,
        public readonly int $winners,
        public readonly int $payout,
    ) {}

    public static function for(int $shopId, string $promoDate): self
    {
        $row = Roll::query()
            ->where('shop_id', $shopId)
            ->whereDate('promo_date', $promoDate)
            ->where('is_test', false)
            ->selectRaw('count(*) as rolls, count(*) filter (where won) as winners, coalesce(sum(payout) filter (where won), 0) as payout')
            ->first();

        return new self((int) $row->rolls, (int) $row->winners, (int) $row->payout);
    }

    public function budgetRemaining(ShopSettings $settings): ?int
    {
        return $settings->daily_budget ? max(0, $settings->daily_budget - $this->payout) : null;
    }

    /**
     * Why the promotion is closed for the rest of the day, or null if it is not.
     */
    public function closedReason(ShopSettings $settings): ?string
    {
        if ($settings->daily_budget && $this->payout >= $settings->daily_budget) {
            return 'budget';
        }
        if ($settings->max_winners_per_day && $this->winners >= $settings->max_winners_per_day) {
            return 'winners';
        }

        return null;
    }
}
