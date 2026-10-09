<?php

namespace App\Promotion;

use App\Models\Block;
use App\Models\ShopSettings;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Decides whether a roll wins.
 *
 * Must be called inside a transaction that already holds a row lock on the
 * shop's settings, so that opening a new block cannot race.
 */
class OddsAllocator
{
    public function allocate(ShopSettings $settings): Allocation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OddsAllocator::allocate must run inside a transaction.');
        }

        $n = max(2, (int) $settings->odds_n);

        if (! $settings->isGuaranteed()) {
            return new Allocation(random_int(1, $n) === 1);
        }

        $block = Block::where('shop_id', $settings->shop_id)
            ->whereNull('completed_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $block) {
            $block = Block::create([
                'shop_id' => $settings->shop_id,
                'size' => $n,
                'winning_position' => random_int(1, $n),
                'next_position' => 1,
            ]);
        }

        $position = $block->next_position;
        $won = $position === $block->winning_position;

        $block->next_position = $position + 1;
        if ($position >= $block->size) {
            $block->completed_at = now();
        }
        $block->save();

        return new Allocation($won, $block->id, $position);
    }
}
