<?php

namespace App\Promotion;

use App\Promotion\Exceptions\PayoutGuardViolation;
use Illuminate\Support\Facades\Log;

/**
 * Hard cap check before any discount code is created.
 */
final class PayoutGuard
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function assert(int $payout, ?int $maxPayout, array $context = []): void
    {
        if ($maxPayout !== null && $maxPayout > 0 && $payout > 0 && $payout <= $maxPayout) {
            return;
        }

        Log::critical('Roll for It payout guard refused a discount code.', [
            'payout' => $payout,
            'max_payout' => $maxPayout,
            ...$context,
        ]);

        throw new PayoutGuardViolation("Payout {$payout} is outside the allowed range (max {$maxPayout}).");
    }
}
