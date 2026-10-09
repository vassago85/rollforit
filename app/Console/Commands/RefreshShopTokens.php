<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Shopify\TokenExchanger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('rfi:refresh-tokens')]
#[Description('Refresh expiring offline access tokens before they lapse')]
class RefreshShopTokens extends Command
{
    public function handle(TokenExchanger $tokens): int
    {
        Shop::whereNotNull('refresh_token')->whereNull('uninstalled_at')
            ->where('access_token_expires_at', '<', now()->addHour())
            ->each(function (Shop $shop) use ($tokens) {
                try {
                    $tokens->refresh($shop);
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Refresh failed for {$shop->domain}: {$e->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
