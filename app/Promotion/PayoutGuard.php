<?php

namespace App\Promotion;

use App\Promotion\Exceptions\PayoutGuardViolation;
use Illuminate\Support\Facades\Log;

/**
 * Hard cap check before any discount code is created. Refuses any payout that
 * is zero, exceeds max_payout, or exceeds the configured percentage of the
 * eligible subtotal.
 */
final class PayoutGuard
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function assert(
        int $payout,
        ?int $maxPayout,
        int $eligibleSubtotal = PHP_INT_MAX,
        ?int $maxPercent = null,
        array $context = [],
    ): void {
        $percentCap = PayoutCalculator::percentCap($eligibleSubtotal, $maxPercent);
        $overMax = $maxPayout === null || $maxPayout <= 0 || $payout > $maxPayout;
        $overPercent = $maxPercent !== null && $maxPercent > 0 && $payout > $percentCap;

        if ($payout > 0 && ! $overMax && ! $overPercent) {
            return;
        }

        Log::critical('Roll for It payout guard refused a discount code.', [
            'payout' => $payout,
            'max_payout' => $maxPayout,
            'max_payout_percent' => $maxPercent,
            'eligible_subtotal' => $eligibleSubtotal,
            'percent_cap' => $percentCap,
            ...$context,
        ]);

        $cap = $overPercent
            ? "{$maxPercent}% of eligible subtotal ({$percentCap})"
            : "max {$maxPayout}";

        throw new PayoutGuardViolation("Payout {$payout} is outside the allowed range ({$cap}).");
    }
}
