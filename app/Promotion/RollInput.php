<?php

namespace App\Promotion;

final class RollInput
{
    /**
     * @param  array<int, array{variant_id?: mixed, quantity?: mixed}>  $lines
     */
    public function __construct(
        public readonly string $email,
        public readonly int $pickedNumber,
        public readonly array $lines,
        public readonly ?string $ip = null,
        public readonly ?string $cartToken = null,
        public readonly ?string $currency = null,
        public readonly ?string $previewToken = null,
    ) {}
}
