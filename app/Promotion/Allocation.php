<?php

namespace App\Promotion;

final class Allocation
{
    public function __construct(
        public readonly bool $won,
        public readonly ?int $blockId = null,
        public readonly ?int $position = null,
    ) {}
}
