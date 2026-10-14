<?php

namespace App\Promotion;

final class PayoutCalculator
{
    /**
     * payout = min(eligible subtotal, max payout, max_percent% of eligible subtotal, remaining daily budget).
     *
     * A null $maxPercent means "no percentage cap" — behaviour matches the old two-argument form.
     * A null $budgetRemaining means "no daily budget cap".
     */
    public static function calculate(
        int $eligibleSubtotal,
        int $maxPayout,
        ?int $maxPercent = null,
        ?int $budgetRemaining = null,
    ): int {
        $payout = min($eligibleSubtotal, $maxPayout);

        if ($maxPercent !== null && $maxPercent > 0) {
            // intdiv rounds towards zero, which is the direction we want — never over the cap.
            $percentCap = intdiv($eligibleSubtotal * $maxPercent, 100);
            $payout = min($payout, $percentCap);
        }

        if ($budgetRemaining !== null) {
            $payout = min($payout, $budgetRemaining);
        }

        return max(0, $payout);
    }

    /**
     * The strict percent cap in cents (0 when $maxPercent is null/zero or the subtotal is non-positive).
     * Used by the guard and diagnostics.
     */
    public static function percentCap(int $eligibleSubtotal, ?int $maxPercent): int
    {
        if ($maxPercent === null || $maxPercent <= 0 || $eligibleSubtotal <= 0) {
            return 0;
        }

        return intdiv($eligibleSubtotal * $maxPercent, 100);
    }
}
