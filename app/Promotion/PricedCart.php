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
}
