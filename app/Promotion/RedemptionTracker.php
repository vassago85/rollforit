<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marks winning rolls as redeemed when the discount code the shopper won is
 * actually used in a paid order.
 *
 * Called from two places so the figures stay consistent regardless of what
 * reaches us first:
 *
 *  - orders/paid webhook (real-time, per-order) — matches as soon as the
 *    shopper checks out, so the merchant's "Rolls today" view flips the
 *    winning row from "Not redeemed yet" to "Redeemed R500" within seconds.
 *  - PromoMetricsService::compute() (periodic, bulk) — matches during the
 *    metrics snapshot so historical rolls get flagged even if the webhook
 *    missed, was delivered after an uninstall, or was fired before this
 *    tracker shipped.
 *
 * Idempotent: re-marking an already-redeemed roll is a no-op. The first
 * matching order wins; a shopper cannot use the same single-use code twice,
 * but if Shopify ever returned duplicates we would not stack them.
 */
final class RedemptionTracker
{
    /**
     * Mark the winning roll (if any) whose discount code appears in this
     * order's discount_codes list. Returns the roll we matched, or null if
     * no roll on this shop sold this discount code.
     *
     * `$orderEmail` is the email on the order (empty / null is fine for guest
     * checkouts without one). When provided, we also compare it to the
     * email_hash the shopper rolled with so merchants can see when a winning
     * code was spent by someone OTHER than the person who rolled — the
     * classic "shared code with a friend" abuse pattern. The result lands in
     * the roll's `redeemed_email_matched` column: true / false / null.
     *
     * @param  list<string>|array<array-key, string>  $discountCodes  Codes exactly as Shopify sent them.
     * @param  int  $discountApplied  Cents actually taken off the order by the code.
     */
    public function markOrderRedemption(
        Shop $shop,
        string $orderId,
        array $discountCodes,
        int $discountApplied,
        CarbonImmutable $placedAt,
        ?string $orderEmail = null,
    ): ?Roll {
        if ($orderId === '' || empty($discountCodes)) {
            return null;
        }

        $codes = array_values(array_filter(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            $discountCodes,
        )));
        if (empty($codes)) {
            return null;
        }

        // whereRaw on upper(discount_code) so a lower-case Shopify payload still
        // matches the exact-case code we created. SQLite's upper() behaves the
        // same as Postgres's for ASCII, which is all we generate.
        $roll = Roll::where('shop_id', $shop->id)
            ->where('won', true)
            ->whereNotNull('discount_code')
            ->whereIn(DB::raw('upper(discount_code)'), $codes)
            ->orderBy('id')
            ->first();

        if (! $roll) {
            return null;
        }

        // If we've already recorded a redemption for this roll, keep it. The
        // single-use nature of our codes means a second order presenting the
        // same code should fail at checkout, but belt-and-braces: don't let a
        // retried webhook or a late metrics backfill overwrite the real one.
        if ($roll->redeemed_at !== null && $roll->redeemed_order_id !== null) {
            return $roll;
        }

        // Never record a redemption larger than the issued payout — our discount
        // is single-use and fixed-amount, but a merchant could stack it with
        // another promo and the order-level discount total would include both.
        $amount = min($discountApplied, (int) $roll->payout);

        $roll->forceFill([
            'redeemed_at' => $placedAt,
            'redeemed_order_id' => $orderId,
            'redeemed_amount' => $amount,
            'redeemed_email_matched' => $this->emailsMatch($roll, $orderEmail),
        ])->save();

        return $roll;
    }

    /**
     * True when the order's email hashes to the same value as the roll's
     * email_hash, false when both sides have an email but they differ, null
     * when we can't compare (the order has no email or the roll never had one).
     */
    private function emailsMatch(Roll $roll, ?string $orderEmail): ?bool
    {
        $orderEmail = $orderEmail !== null ? trim($orderEmail) : '';
        if ($orderEmail === '' || empty($roll->email_hash)) {
            return null;
        }

        return hash_equals($roll->email_hash, EmailHasher::hash($orderEmail));
    }
}
