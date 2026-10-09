<?php

namespace App\Console\Commands;

use App\Models\Roll;
use App\Models\ShopSettings;
use App\Promotion\OddsAllocator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Used by the concurrency test: many of these run as separate processes at once.
 */
#[Signature('rfi:test-allocate {shop_id} {count=1}')]
#[Description('Allocate rolls through the real allocator (testing only)')]
#[Hidden]
class TestAllocate extends Command
{
    public function handle(OddsAllocator $allocator): int
    {
        if (app()->isProduction()) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $shopId = (int) $this->argument('shop_id');

        for ($i = 0; $i < (int) $this->argument('count'); $i++) {
            DB::transaction(function () use ($allocator, $shopId) {
                $settings = ShopSettings::where('shop_id', $shopId)->lockForUpdate()->firstOrFail();
                $allocation = $allocator->allocate($settings);

                Roll::create([
                    'shop_id' => $shopId,
                    'block_id' => $allocation->blockId,
                    'position' => $allocation->position,
                    'email_hash' => bin2hex(random_bytes(32)),
                    'promo_date' => now()->toDateString(),
                    'cart_subtotal' => 0,
                    'eligible_subtotal' => 0,
                    'picked_number' => 1,
                    'displayed_number' => 1,
                    'won' => $allocation->won,
                    'payout' => 0,
                    'is_test' => false,
                ]);
            });
        }

        return self::SUCCESS;
    }
}
