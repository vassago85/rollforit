<?php

namespace App\Console\Commands;

use App\Models\EmailVerifyAttempt;
use App\Models\Roll;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rfi:prune')]
#[Description('Clear old IP addresses, delete rolls past retention, prune verify attempts')]
class PruneRolls extends Command
{
    public function handle(): int
    {
        $ips = Roll::whereNotNull('ip')
            ->where('created_at', '<', now()->subDays((int) config('shopify.retention.ip_days')))
            ->update(['ip' => null]);

        $rolls = Roll::where('created_at', '<', now()->subYears((int) config('shopify.retention.roll_years')))->delete();

        $attempts = EmailVerifyAttempt::where('created_at', '<', now()->subDays((int) config('shopify.retention.verify_attempt_days')))->delete();

        $this->info("Cleared {$ips} IP addresses, deleted {$rolls} rolls, deleted {$attempts} verify attempts.");

        return self::SUCCESS;
    }
}
