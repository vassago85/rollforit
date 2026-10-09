<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Promotion\DayStats;
use App\Promotion\Exceptions\PayoutGuardViolation;
use App\Promotion\Exceptions\RollRejected;
use App\Promotion\PromoWindow;
use App\Promotion\RollInput;
use App\Promotion\RollService;
use App\Promotion\WidgetCopy;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Server-to-server endpoints HuntexPos calls. Protected by the shopify.pos middleware
 * (HMAC + nonce), never reachable from a browser.
 */
class PosController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();
        $window = new PromoWindow($settings, $shop->timezone);
        $symbol = Money::symbolFor($shop->currency);

        $reason = null;
        if (! $settings->enabled || ! $settings->canEnable()) {
            $reason = 'closed';
        } elseif (! $window->isOpen()) {
            $reason = 'upcoming';
        } elseif (DayStats::for($shop->id, $window->promoDate())->closedReason($settings) !== null) {
            $reason = 'sold_out';
        }

        $next = null;
        if ($reason !== 'closed' && $settings->show_countdown) {
            $next = $window->nextOpening(skipToday: $reason !== 'upcoming');
        }

        return response()->json([
            'open' => $reason === null,
            'reason' => $reason,
            'currency' => $shop->currency,
            'currency_symbol' => $symbol,
            'min_order' => (int) $settings->min_order,
            'min_order_formatted' => Money::format($settings->min_order, $symbol),
            'max_payout' => (int) $settings->max_payout,
            'max_payout_formatted' => Money::format($settings->max_payout, $symbol),
            'odds' => WidgetCopy::oddsStatement($settings),
            'disclaimer' => WidgetCopy::pickDisclaimer(),
            'headline' => $settings->headline ?: WidgetCopy::DEFAULT_HEADLINE,
            'next_roll' => $next ? [
                'starts_at' => $next->toIso8601String(),
                'starts_in' => max(0, $next->getTimestamp() - $window->now()->getTimestamp()),
                'label' => WidgetCopy::nextRollLabel($next, $window->now()),
            ] : null,
            'animation' => [
                'die_color' => $settings->die_color,
                'number_color' => $settings->die_number_color,
                'accent_color' => $settings->accent_color,
                'duration_ms' => (int) $settings->roll_duration_ms,
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function roll(Request $request, RollService $rolls): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30'],
            'picked_number' => ['required', 'integer', 'between:1,10'],
            // POS tills send a single cents amount — product-level eligibility is enforced
            // in-store by the operator at the till, not by this app.
            'subtotal_cents' => ['required', 'integer', 'min:0'],
            'operator_id' => ['nullable', 'string', 'max:120'],
            'cart_token' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $shop = $this->shop($request);

        try {
            $result = $rolls->roll($shop, new RollInput(
                channel: \App\Models\Roll::CHANNEL_POS,
                pickedNumber: (int) $data['picked_number'],
                email: $data['email'] ?? null,
                phone: $data['phone'] ?? null,
                posSubtotalCents: (int) $data['subtotal_cents'],
                ip: $request->attributes->get('customer_ip'),
                cartToken: isset($data['operator_id']) ? 'pos:'.$data['operator_id'] : ($data['cart_token'] ?? null),
                currency: $data['currency'] ?? null,
            ));
        } catch (RollRejected $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);
        } catch (PayoutGuardViolation) {
            return response()->json([
                'error' => 'prize_unavailable',
                'message' => 'Could not issue the prize. The customer has not been charged.',
            ], 500);
        } catch (\Throwable $e) {
            Log::error('POS roll failed', ['shop' => $shop->domain, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'unavailable', 'message' => 'Service temporarily unavailable.'], 503);
        }

        return response()->json($result, 200, ['Cache-Control' => 'no-store']);
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
