<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Promotion\DayStats;
use App\Promotion\Exceptions\PayoutGuardViolation;
use App\Promotion\Exceptions\RollRejected;
use App\Promotion\PreviewLinks;
use App\Promotion\PromoWindow;
use App\Promotion\RollInput;
use App\Promotion\RollService;
use App\Promotion\WidgetCopy;
use App\Shopify\Exceptions\ShopifyApiException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Storefront endpoints behind the Shopify app proxy (/apps/roll-for-it/*).
 */
class ProxyController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();
        $window = new PromoWindow($settings, $shop->timezone);
        $symbol = Money::symbolFor($shop->currency);
        $test = PreviewLinks::isValid($settings, $request->query('preview'));

        $reason = null;
        $stats = null;
        if (! $test) {
            if (! $settings->enabled || ! $settings->canEnable()) {
                $reason = 'closed';
            } elseif (! $window->isOpen()) {
                $reason = 'upcoming';
            } else {
                $stats = DayStats::for($shop->id, $window->promoDate());
                if ($stats->closedReason($settings) !== null) {
                    $reason = 'sold_out';
                }
            }
        } elseif (! $settings->min_order || ! $settings->max_payout) {
            $reason = 'closed';
        }

        $next = null;
        if (! $test && $reason !== 'closed' && $settings->show_countdown) {
            $next = $window->nextOpening(skipToday: $reason !== 'upcoming');
        }

        if ($reason === 'closed' || ($reason === 'upcoming' && $next === null)) {
            return $this->noStore(['open' => false, 'reason' => 'closed']);
        }

        // Social proof: only share once there's at least one real winner today,
        // so the widget never shows "0 winners".
        $todayStats = null;
        if ($stats !== null && $stats->winners > 0) {
            $todayStats = [
                'winners' => $stats->winners,
                'total_payout' => $stats->payout,
                'total_payout_formatted' => Money::format($stats->payout, $symbol),
                'message' => WidgetCopy::socialProof($stats->winners, $stats->payout, $symbol),
            ];
        }

        return $this->noStore([
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
            'one_roll' => 'One roll per customer per day.',
            'sold_out_message' => 'Today\'s prizes have all been won. Try again next promo day.',
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
        ]);
    }

    public function roll(Request $request, RollService $rolls): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'picked_number' => ['required', 'integer', 'between:1,10'],
            'lines' => ['required', 'array', 'min:1', 'max:'.config('shopify.roll.max_cart_lines')],
            'lines.*.variant_id' => ['required'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('shopify.roll.max_line_quantity')],
            'cart_token' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'preview' => ['nullable', 'string', 'max:100'],
        ]);

        $shop = $this->shop($request);

        try {
            $result = $rolls->roll($shop, new RollInput(
                email: $data['email'],
                pickedNumber: (int) $data['picked_number'],
                lines: $data['lines'],
                ip: $request->attributes->get('customer_ip'),
                cartToken: $data['cart_token'] ?? null,
                currency: $data['currency'] ?? null,
                previewToken: $data['preview'] ?? null,
            ));
        } catch (RollRejected $e) {
            return $this->noStore(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);
        } catch (PayoutGuardViolation) {
            return $this->noStore([
                'error' => 'prize_unavailable',
                'message' => 'Sorry, we could not issue your prize. Please contact the store.',
            ], 500);
        } catch (ShopifyApiException $e) {
            Log::error('Roll failed on Shopify API', ['shop' => $shop->domain, 'error' => $e->getMessage()]);

            return $this->noStore(['error' => 'unavailable', 'message' => 'Something went wrong. Please try again.'], 503);
        }

        return $this->noStore($result);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function noStore(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store']);
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
