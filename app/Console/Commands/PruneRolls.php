<?php

namespace App\Console\Commands;

use App\Models\Roll;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rfi:prune')]
#[Description('Clear old IP addresses and delete rolls past the retention period')]
class PruneRolls extends Command
{
    public function handle(): int
    {
        $ips = Roll::whereNotNull('ip')
            ->where('created_at', '<', now()->subDays((int) config('shopify.retention.ip_days')))
            ->update(['ip' => null]);

        $rolls = Roll::where('created_at', '<', now()->subYears((int) config('shopify.retention.roll_years')))->delete();

        $this->info("Cleared {$ips} IP addresses, deleted {$rolls} rolls.");

        return self::SUCCESS;
    }
}
