<?php

namespace App\Promotion;

final class PricedCart
{
    /**
     * @param  list<string>  $eligibleProductIds
     */
    public function __construct(
        public readonly int $cartSubtotal,
        public readonly int $giftCardSubtotal,
        public readonly int $eligibleSubtotal,
        public readonly array $eligibleProductIds,
    ) {}

    /**
     * Cart value that counts towards min_order (gift cards never count).
     */
    public function qualifyingSubtotal(): int
    {
        return $this->cartSubtotal - $this->giftCardSubtotal;
    }

    /**
     * POS tills do not expose line-level product IDs; the operator sends a single subtotal
     * which both qualifies the roll and bounds the payout.
     */
    public static function fromPos(int $subtotalCents): self
    {
        return new self(
            cartSubtotal: $subtotalCents,
            giftCardSubtotal: 0,
            eligibleSubtotal: $subtotalCents,
            eligibleProductIds: [],
        );
    }
}
