<?php

namespace App\Promotion;

final class PayoutCalculator
{
    /**
     * payout = min(eligible subtotal, max payout, remaining daily budget).
     */
    public static function calculate(int $eligibleSubtotal, int $maxPayout, ?int $budgetRemaining = null): int
    {
        $payout = min($eligibleSubtotal, $maxPayout);
        if ($budgetRemaining !== null) {
            $payout = min($payout, $budgetRemaining);
        }

        return max(0, $payout);
    }
}
