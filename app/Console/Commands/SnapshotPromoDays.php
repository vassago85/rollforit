<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Promotion\PromoMetricsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('rfi:snapshot-promo-days')]
#[Description('Save final revenue vs prizes figures for promo days that have closed')]
class SnapshotPromoDays extends Command
{
    public function handle(PromoMetricsService $metrics): int
    {
        Shop::whereNotNull('access_token')->whereNull('uninstalled_at')->each(function (Shop $shop) use ($metrics) {
            foreach ($metrics->datesDueForSnapshot($shop) as $date) {
                try {
                    $metrics->snapshot($shop, $date, final: true);
                    $this->info("Snapshot saved: {$shop->domain} {$date}");
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Snapshot failed: {$shop->domain} {$date}: {$e->getMessage()}");
                }
            }
        });

        return self::SUCCESS;
    }
}
