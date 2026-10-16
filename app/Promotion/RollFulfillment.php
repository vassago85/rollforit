<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves rolls to Shopify orders.
 *
 * One roll per promo day is handled by the per-day unique indexes. Across promo days,
 * a roller must have **completed a paid order after their last roll** before they can
 * roll again — otherwise it is trivial to roll, see a losing result (or an inconvenient
 * win), abandon the cart and come back next week for another free attempt.
 *
 * The storefront enforces this up front via `owedRoll()`; the webhooks keep the DB in
 * sync as orders are paid or cancelled.
 */
final class RollFulfillment
{
    /**
     * The oldest unfulfilled, real roll for this identity. Null when nothing is owed.
     *
     * Only rolls strictly before `today` are considered — the per-day index already
     * prevents a second roll on the same promo day.
     */
    public function owedRoll(
        Shop $shop,
        ?string $emailHash,
        ?string $phoneHash,
        ?string $customerHash,
        ?string $ip,
        string $today,
    ): ?Roll {
        if (! $emailHash && ! $phoneHash && ! $customerHash && ! $ip) {
            return null;
        }

        return Roll::where('shop_id', $shop->id)
            ->where('is_test', false)
            ->whereNull('fulfilled_at')
            ->whereDate('promo_date', '<', $today)
            ->where(fn (Builder $q) => $this->identityMatch(
                $q, $emailHash, $phoneHash, $customerHash, $ip, matchGuestIpOnly: true,
            ))
            ->orderBy('promo_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * Mark the newest unfulfilled roll created on or before the order as paid.
     *
     * @return Roll|null The roll we matched, or null when the order has no prior roll.
     */
    public function markOrderPaid(
        Shop $shop,
        string $orderId,
        ?string $emailHash,
        ?string $customerHash,
        ?string $ip,
        CarbonImmutable $orderPlacedAt,
    ): ?Roll {
        if ($orderId === '') {
            return null;
        }
        if (! $emailHash && ! $customerHash && ! $ip) {
            return null;
        }

        // Keep the row stable even if the webhook retries: if we already linked this
        // order to a roll, just re-stamp it (newer orders can bump a stale timestamp).
        $existing = Roll::where('shop_id', $shop->id)
            ->where('fulfilled_order_id', $orderId)
            ->first();
        if ($existing) {
            $existing->forceFill(['fulfilled_at' => $orderPlacedAt])->save();

            return $existing;
        }

        $roll = Roll::where('shop_id', $shop->id)
            ->where('is_test', false)
            ->whereNull('fulfilled_at')
            ->where('created_at', '<=', $orderPlacedAt)
            ->where(fn (Builder $q) => $this->identityMatch(
                $q, $emailHash, phoneHash: null, customerHash: $customerHash, ip: $ip, matchGuestIpOnly: true,
            ))
            ->orderByDesc('created_at')
            ->first();

        if (! $roll) {
            return null;
        }

        $roll->forceFill([
            'fulfilled_at' => $orderPlacedAt,
            'fulfilled_order_id' => $orderId,
        ])->save();

        return $roll;
    }

    /**
     * Undo a previous fulfillment when the order is cancelled, so the roller stays
     * blocked until they actually complete another purchase.
     */
    public function markOrderCancelled(Shop $shop, string $orderId): int
    {
        if ($orderId === '') {
            return 0;
        }

        return Roll::where('shop_id', $shop->id)
            ->where('fulfilled_order_id', $orderId)
            ->update([
                'fulfilled_at' => null,
                'fulfilled_order_id' => null,
            ]);
    }

    /**
     * Shared identity predicate. Guest IP matches only match other guest rows so a
     * shared café IP cannot pull in someone else's logged-in roll.
     *
     * @param  Builder<Roll>  $q
     */
    private function identityMatch(
        Builder $q,
        ?string $emailHash,
        ?string $phoneHash,
        ?string $customerHash,
        ?string $ip,
        bool $matchGuestIpOnly,
    ): void {
        if ($emailHash) {
            $q->orWhere('email_hash', $emailHash);
        }
        if ($phoneHash) {
            $q->orWhere('phone_hash', $phoneHash);
        }
        if ($customerHash) {
            $q->orWhere('shopify_customer_hash', $customerHash);
        }
        if ($ip) {
            if ($matchGuestIpOnly) {
                $q->orWhere(function (Builder $guest) use ($ip) {
                    $guest->where('ip', $ip)
                        ->whereNull('email_hash')
                        ->whereNull('phone_hash')
                        ->whereNull('shopify_customer_hash');
                });
            } else {
                $q->orWhere('ip', $ip);
            }
        }
    }
}
