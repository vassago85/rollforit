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
    ) {
        if ($this->channel === Roll::CHANNEL_POS && $this->posSubtotalCents === null) {
            throw new InvalidArgumentException('POS rolls require posSubtotalCents.');
        }
        if ($this->channel === Roll::CHANNEL_ONLINE && ($this->email === null || $this->email === '')) {
            throw new InvalidArgumentException('Online rolls require an email.');
        }
        if (($this->email === null || $this->email === '') && ($this->phone === null || $this->phone === '')) {
            throw new InvalidArgumentException('Either email or phone must be provided.');
        }
    }
}
