<?php

use App\Models\Block;
use App\Models\Roll;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

it('never gives two concurrent rolls the same block position', function () {
    $shop = makeShop(['odds_n' => 10]);
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => 'rollforit_test',
        'CACHE_STORE' => 'array',
        'LOG_CHANNEL' => 'null',
    ];

    $processes = [];
    foreach (range(1, 8) as $i) {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'rfi:test-allocate', (string) $shop->id, '25'], base_path(), $env, null, 180);
        $process->start();
        $processes[] = $process;
    }
    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
    }

    $rolls = Roll::where('shop_id', $shop->id)->get();
    $slots = $rolls->map(fn (Roll $r) => $r->block_id.':'.$r->position);

    expect($rolls)->toHaveCount(200)
        ->and($slots->unique())->toHaveCount(200)
        ->and(Block::where('shop_id', $shop->id)->count())->toBe(20);

    foreach ($rolls->groupBy('block_id') as $blockRolls) {
        expect($blockRolls)->toHaveCount(10)
            ->and($blockRolls->where('won', true))->toHaveCount(1);
    }
});
