<?php

namespace App\Promotion;

use App\Mail\MerchantBudgetDepleted;
use App\Mail\MerchantRollWon;
use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Mail\Mailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fires the two merchant emails Roll4It sends:
 *   - A win notification per real customer win (never for tests or simulator).
 *   - A one-off "today's budget is used up" notification the moment the daily
 *     prize budget hits zero. Guarded with a cache lock so repeated losing
 *     rolls after depletion don't re-send it.
 */
final class MerchantNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Cache $cache,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Called after each roll is recorded. Fires win + budget mails as needed.
     */
    public function rollLanded(Shop $shop, ShopSettings $settings, Roll $roll): void
    {
        if ($roll->is_test || ! $shop->email) {
            return;
        }

        if ($roll->won) {
            $this->send(fn () => $this->mailer->send(new MerchantRollWon($shop, $settings, $roll)),
                "roll-won shop={$shop->id} roll={$roll->id}");
        }

        $this->maybeBudgetDepleted($shop, $settings, $roll);
    }

    private function maybeBudgetDepleted(Shop $shop, ShopSettings $settings, Roll $roll): void
    {
        if (! $settings->daily_budget || $settings->daily_budget <= 0) {
            return;
        }

        $promoDate = $roll->promo_date?->toDateString() ?? (string) $roll->promo_date;
        if ($promoDate === '') {
            return;
        }

        // Fresh totals (includes this roll because the DB write already landed).
        $stats = DayStats::for($shop->id, $promoDate);
        if ($stats->payout < (int) $settings->daily_budget) {
            return;
        }

        // Cache lock: one email per shop per promo date, regardless of how many
        // losing rolls arrive after the budget tips over.
        $key = "rfi:budget-depleted-notified:{$shop->id}:{$promoDate}";
        if (! $this->cache->add($key, 1, now()->addDays(7))) {
            return;
        }

        // Give the merchant the actual next promo date to look forward to, not
        // a generic "on your next promo day" line. skipToday is on so today's
        // (now-depleted) window never comes back as the "next" one.
        $window = new PromoWindow($settings, $shop->timezone);
        $nextOpening = $window->nextOpening(skipToday: true);

        $this->send(
            fn () => $this->mailer->send(new MerchantBudgetDepleted(
                shop: $shop,
                settings: $settings,
                promoDate: $promoDate,
                totalPaidOut: $stats->payout,
                winners: $stats->winners,
                nextOpening: $nextOpening,
            )),
            "budget-depleted shop={$shop->id} date={$promoDate}",
        );
    }

    private function send(callable $dispatch, string $context): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            // Never block or fail a roll because an email failed.
            $this->log->warning("MerchantNotifier: {$context} failed: {$e->getMessage()}");
        }
    }
}
