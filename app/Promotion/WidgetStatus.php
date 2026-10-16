<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The storefront widget's /status payload, shared by the app proxy and the admin Test lab.
 */
final class WidgetStatus
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(
        Shop $shop,
        ShopSettings $settings,
        PromoWindow $window,
        ?string $reason,
        ?CarbonImmutable $next,
        bool $test,
        bool $loggedIn,
        ?DayStats $stats,
        ?Roll $owedRoll = null,
    ): array {
        $symbol = Money::symbolFor($shop->currency);

        // Social proof only appears once someone has won, so the widget never shows "0 winners".
        $todayStats = null;
        if ($stats !== null && $stats->winners > 0) {
            $todayStats = [
                'winners' => $stats->winners,
                'total_payout' => $stats->payout,
                'total_payout_formatted' => Money::format($stats->payout, $symbol),
                'message' => WidgetCopy::socialProof($stats->winners, $stats->payout, $symbol),
            ];
        }

        return [
            'open' => $reason === null,
            'reason' => $reason,
            'next_roll' => $next ? [
                'starts_at' => $next->toIso8601String(),
                'starts_in' => max(0, $next->getTimestamp() - $window->now()->getTimestamp()),
                'label' => WidgetCopy::nextRollLabel($next, $window->now()),
            ] : null,
            'test' => $test,
            'currency' => $shop->currency,
            'min_order' => (int) $settings->min_order,
            'banner' => WidgetCopy::banner($shop, $settings),
            'headline' => $settings->headline ?: WidgetCopy::DEFAULT_HEADLINE,
            'odds' => WidgetCopy::oddsStatement($settings),
            'disclaimer' => WidgetCopy::pickDisclaimer(),
            'spend_to_roll' => WidgetCopy::spendToRoll($shop, $settings),
            'one_roll' => 'One roll per person per day.',
            'sold_out_message' => 'Today\'s prizes have all been won. Try again next promo day.',
            'logged_in' => $loggedIn,
            'terms_url' => $settings->terms_url,
            'min_order_formatted' => Money::format($settings->min_order, $symbol),
            'max_payout_formatted' => Money::format($settings->max_payout, $symbol),
            'promo_date' => $window->promoDate(),
            'previous_promo_date' => $window->previousPromoDate(),
            'today_stats' => $todayStats,
            'animation' => [
                'die_color' => $settings->die_color,
                'number_color' => $settings->die_number_color,
                'accent_color' => $settings->accent_color,
                'duration_ms' => (int) $settings->roll_duration_ms,
                'sound' => (bool) $settings->sound_enabled,
                'celebration' => (bool) $settings->celebration_enabled,
            ],
            'drawer_selector' => $settings->drawer_selector,
            'owed_roll' => $owedRoll ? [
                'won' => (bool) $owedRoll->won,
                'promo_date' => $owedRoll->promo_date?->toDateString(),
                'code' => $owedRoll->won && $owedRoll->code_status === Roll::CODE_CREATED ? $owedRoll->discount_code : null,
                'message' => $owedRoll->won
                    ? 'Use your winning code at checkout to unlock your next roll.'
                    : 'Complete a purchase to unlock your next roll.',
            ] : null,
        ];
    }
}
