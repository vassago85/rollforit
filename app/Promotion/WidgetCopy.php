<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Storefront copy. The picked number never decides the outcome, and the copy says so.
 */
final class WidgetCopy
{
    public const DEFAULT_HEADLINE = 'Roll for it!';

    public const DEFAULT_WIN = 'You won {payout} off your order! Code {code} has been applied to your cart.';

    public const DEFAULT_LOSE = 'Not this time. Thanks for rolling, and good luck next time!';

    /** POS wins never produce a Shopify code; the till applies the win straight to the invoice. */
    public const DEFAULT_POS_WIN = 'You won {payout} off this sale! The discount is on your invoice.';

    public static function oddsStatement(ShopSettings $settings): string
    {
        $n = (int) $settings->odds_n;

        return $settings->isGuaranteed()
            ? "One in every {$n} entries wins."
            : "Each roll has a 1 in {$n} chance to win.";
    }

    public static function pickDisclaimer(): string
    {
        return 'Your number is just for fun. It does not change your odds: the result is decided when you roll.';
    }

    public static function banner(Shop $shop, ShopSettings $settings): string
    {
        $symbol = Money::symbolFor($shop->currency);

        return sprintf(
            'Spend %s, win up to %s off. T&Cs apply.',
            Money::format($settings->min_order, $symbol),
            Money::format($settings->max_payout, $symbol),
        );
    }

    public static function spendToRoll(Shop $shop, ShopSettings $settings): string
    {
        return 'Spend '.Money::format($settings->min_order, Money::symbolFor($shop->currency)).' to roll';
    }

    /**
     * "today at 08:00", "tomorrow at 08:00", "Friday at 08:00", or "next Friday at 08:00" a week out.
     * Both times must be in the shop's timezone.
     */
    public static function nextRollLabel(CarbonImmutable $next, CarbonImmutable $now): string
    {
        $days = (int) $now->startOfDay()->diffInDays($next->startOfDay());
        $time = $next->format('H:i');

        return match (true) {
            $days === 0 => "today at {$time}",
            $days === 1 => "tomorrow at {$time}",
            $days >= 7 => "next {$next->format('l')} at {$time}",
            default => "{$next->format('l')} at {$time}",
        };
    }

    public static function resultMessage(Shop $shop, ShopSettings $settings, Roll $roll): string
    {
        $isPos = $roll->channel === Roll::CHANNEL_POS;
        $template = match (true) {
            $roll->won && $isPos => self::DEFAULT_POS_WIN,
            $roll->won => $settings->win_message ?: self::DEFAULT_WIN,
            default => $settings->lose_message ?: self::DEFAULT_LOSE,
        };

        return strtr($template, [
            '{payout}' => Money::format($roll->payout, Money::symbolFor($shop->currency)),
            '{code}' => (string) $roll->discount_code,
        ]);
    }
}
