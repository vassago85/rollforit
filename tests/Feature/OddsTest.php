<?php

use App\Models\Block;
use App\Models\ShopSettings;
use App\Promotion\OddsAllocator;
use Illuminate\Support\Facades\DB;

function allocateMany(ShopSettings $settings, int $count): array
{
    $allocator = app(OddsAllocator::class);
    $results = [];
    for ($i = 0; $i < $count; $i++) {
        $results[] = DB::transaction(function () use ($allocator, $settings) {
            $locked = ShopSettings::whereKey($settings->id)->lockForUpdate()->first();

            return $allocator->allocate($locked);
        });
    }

    return $results;
}

it('has exactly one winner per block over many blocks in guaranteed mode', function () {
    $shop = makeShop(['odds_n' => 10]);
    $results = allocateMany($shop->settings, 1000);

    $byBlock = collect($results)->groupBy('blockId');

    expect($byBlock)->toHaveCount(100);
    foreach ($byBlock as $rolls) {
        expect($rolls)->toHaveCount(10)
            ->and($rolls->where('won', true))->toHaveCount(1)
            ->and($rolls->pluck('position')->sort()->values()->all())->toBe(range(1, 10));
    }
    expect(Block::whereNull('completed_at')->count())->toBe(0);
});

it('spreads the winning position across the block', function () {
    $shop = makeShop(['odds_n' => 10]);
    $results = allocateMany($shop->settings, 2000);

    $winningPositions = collect($results)->where('won', true)->pluck('position')->unique();

    expect($winningPositions->count())->toBeGreaterThan(5);
});

it('carries an unfinished block over to the next promo day', function () {
    $shop = makeShop(['odds_n' => 10]);
    $first = allocateMany($shop->settings, 4);

    $this->travel(1)->days();
    $next = allocateMany($shop->settings, 1)[0];

    expect($next->blockId)->toBe($first[0]->blockId)->and($next->position)->toBe(5);
});

it('converges near 1/N in chance mode over 10,000 rolls', function () {
    $shop = makeShop(['odds_mode' => ShopSettings::MODE_CHANCE, 'odds_n' => 10]);
    $results = allocateMany($shop->settings, 10_000);

    $rate = collect($results)->where('won', true)->count() / 10_000;

    expect($rate)->toBeGreaterThan(0.085)->toBeLessThan(0.115)
        ->and(Block::count())->toBe(0);
});
