<?php

namespace App\Promotion;

use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Promotion\Exceptions\RollRejected;
use App\Support\Money;

/**
 * Runs the full roll decision in-memory for the admin "Test lab".
 *
 * - No database writes (Roll instance is never saved).
 * - No Shopify API calls (fake preview code is minted locally).
 * - No rate limiting, no budget consumption, no streaks or stats.
 *
 * The result shape mirrors RollService::present() exactly so the simulator UI
 * can reuse the same widget rendering path the storefront uses.
 */
final class RollSimulator
{
    /**
     * @return array{result: array<string, mixed>, diagnostics: array<string, mixed>}
     */
    public static function run(
        Shop $shop,
        ShopSettings $settings,
        int $cartSubtotalCents,
        string $outcome,
        int $pickedNumber,
        string $channel = Roll::CHANNEL_ONLINE,
    ): array {
        $outcome = in_array($outcome, ['win', 'loss', 'random'], true) ? $outcome : 'random';
        $pickedNumber = max(1, min(10, $pickedNumber));

        if (! $settings->min_order || ! $settings->max_payout) {
            throw new RollRejected(RollRejected::CLOSED, 'Set a minimum order and maximum payout first.');
        }
        if ($cartSubtotalCents < (int) $settings->min_order) {
            throw new RollRejected(RollRejected::BELOW_MIN, WidgetCopy::spendToRoll($shop, $settings).'.');
        }

        $won = match ($outcome) {
            'win' => true,
            'loss' => false,
            default => random_int(1, (int) $settings->odds_n) === 1,
        };

        $maxPayout = (int) $settings->max_payout;
        $maxPercent = $settings->max_payout_percent !== null ? (int) $settings->max_payout_percent : null;
        $payout = $won
            ? PayoutCalculator::calculate($cartSubtotalCents, $maxPayout, $maxPercent)
            : 0;
        $won = $won && $payout > 0;

        $isPos = $channel === Roll::CHANNEL_POS;
        $code = $won && ! $isPos ? 'SIM-'.self::randomCode() : null;

        // Build an in-memory Roll (never saved) so WidgetCopy::resultMessage sees
        // the same model shape it would in production.
        $roll = new Roll;
        $roll->shop_id = $shop->id;
        $roll->picked_number = $pickedNumber;
        $roll->displayed_number = $won ? $pickedNumber : RollService::losingFace($pickedNumber);
        $roll->won = $won;
        $roll->payout = $payout;
        $roll->discount_code = $code;
        $roll->channel = $channel;
        $roll->is_test = true;
        $roll->cart_subtotal = $cartSubtotalCents;
        $roll->eligible_subtotal = $cartSubtotalCents;

        $symbol = Money::symbolFor($shop->currency);
        $percentCap = PayoutCalculator::percentCap($cartSubtotalCents, $maxPercent);
        $potentialPayout = $won ? null : PayoutCalculator::calculate($cartSubtotalCents, $maxPayout, $maxPercent);

        return [
            'result' => [
                'simulated' => true,
                'repeat' => false,
                'test' => true,
                'won' => $won,
                'displayed_number' => $roll->displayed_number,
                'picked_number' => $pickedNumber,
                'payout' => $payout,
                'payout_formatted' => Money::format($payout, $symbol),
                'potential_payout' => $potentialPayout,
                'potential_payout_formatted' => $potentialPayout !== null && $potentialPayout > 0
                    ? Money::format($potentialPayout, $symbol)
                    : null,
                'code' => $code,
                'code_ready' => true,
                'ends_at' => null,
                'message' => WidgetCopy::resultMessage($shop, $settings, $roll),
                'channel' => $channel,
            ],
            'diagnostics' => [
                'cart_subtotal' => $cartSubtotalCents,
                'cart_subtotal_formatted' => Money::format($cartSubtotalCents, $symbol),
                'min_order' => (int) $settings->min_order,
                'min_order_formatted' => Money::format((int) $settings->min_order, $symbol),
                'max_payout' => $maxPayout,
                'max_payout_formatted' => Money::format($maxPayout, $symbol),
                'max_payout_percent' => $maxPercent,
                'percent_cap' => $percentCap,
                'percent_cap_formatted' => $maxPercent !== null ? Money::format($percentCap, $symbol) : null,
                'binding_cap' => self::whichCapBit($won, $cartSubtotalCents, $maxPayout, $percentCap, $maxPercent),
                'odds' => WidgetCopy::oddsStatement($settings),
                'channel' => $channel,
            ],
        ];
    }

    /**
     * Which cap decided the payout, for the diagnostic card.
     */
    private static function whichCapBit(bool $won, int $cart, int $maxPayout, int $percentCap, ?int $maxPercent): string
    {
        if (! $won) {
            return 'none';
        }
        $candidates = [
            'cart' => $cart,
            'max_payout' => $maxPayout,
        ];
        if ($maxPercent !== null && $maxPercent > 0) {
            $candidates['percent'] = $percentCap;
        }

        return (string) array_search(min($candidates), $candidates, true);
    }

    private static function randomCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
