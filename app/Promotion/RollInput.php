<?php

namespace App\Promotion;

use App\Models\Roll;
use InvalidArgumentException;

final class RollInput
{
    /**
     * @param  array<int, array{variant_id?: mixed, quantity?: mixed}>  $lines Online rolls only.
     */
    public function __construct(
        public readonly int $pickedNumber,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly array $lines = [],
        public readonly ?string $ip = null,
        public readonly ?string $cartToken = null,
        public readonly ?string $currency = null,
        public readonly ?string $previewToken = null,
        public readonly string $channel = Roll::CHANNEL_ONLINE,
        public readonly ?int $posSubtotalCents = null,
        public readonly ?string $shopifyCustomerId = null,
    ) {
        if ($this->channel === Roll::CHANNEL_POS && $this->posSubtotalCents === null) {
            throw new InvalidArgumentException('POS rolls require posSubtotalCents.');
        }
        if ($this->channel === Roll::CHANNEL_ONLINE && ! $this->hasOnlineIdentity()) {
            throw new InvalidArgumentException('Online rolls require a Shopify login or an email.');
        }
        if ($this->channel === Roll::CHANNEL_POS && ($this->email === null || $this->email === '') && ($this->phone === null || $this->phone === '')) {
            throw new InvalidArgumentException('Either email or phone must be provided.');
        }
    }

    public function hasOnlineIdentity(): bool
    {
        return ($this->shopifyCustomerId !== null && $this->shopifyCustomerId !== '')
            || ($this->email !== null && $this->email !== '');
    }
}
