<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Promotion\DayStats;
use App\Promotion\Exceptions\PayoutGuardViolation;
use App\Promotion\Exceptions\RollRejected;
use App\Promotion\IdentityHasher;
use App\Promotion\PreviewLinks;
use App\Promotion\PromoWindow;
use App\Promotion\RollFulfillment;
use App\Promotion\RollInput;
use App\Promotion\RollService;
use App\Promotion\WidgetStatus;
use App\Shopify\Exceptions\ShopifyApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Storefront endpoints behind the Shopify app proxy (/apps/roll-for-it/*).
 */
class ProxyController extends Controller
{
    public function status(Request $request, RollFulfillment $fulfillment): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();
        $window = new PromoWindow($settings, $shop->timezone);
        $test = PreviewLinks::isValid($settings, $request->query('preview'));

        $reason = null;
        $stats = null;
        $owedRoll = null;
        if (! $test) {
            if (! $settings->enabled || ! $settings->canEnable()) {
                $reason = 'closed';
            } elseif (! $window->isOpen()) {
                $reason = 'upcoming';
            } else {
                $stats = DayStats::for($shop->id, $window->promoDate());
                if ($stats->closedReason($settings) !== null) {
                    $reason = 'sold_out';
                } else {
                    $customerId = $request->attributes->get('logged_in_customer_id');
                    $customerHash = $customerId ? IdentityHasher::hashShopifyCustomerId((string) $customerId) : null;
                    $owedRoll = $fulfillment->owedRoll(
                        $shop,
                        emailHash: null,
                        phoneHash: null,
                        customerHash: $customerHash,
                        ip: $request->attributes->get('customer_ip'),
                        today: $window->promoDate(),
                    );
                    if ($owedRoll) {
                        $reason = 'owed';
                    }
                }
            }
        } elseif (! $settings->min_order || ! $settings->max_payout) {
            $reason = 'closed';
        }

        $next = null;
        if (! $test && $reason !== 'closed' && $reason !== 'owed' && $settings->show_countdown) {
            $next = $window->nextOpening(skipToday: $reason !== 'upcoming');
        }

        if ($reason === 'closed' || ($reason === 'upcoming' && $next === null)) {
            return $this->noStore(['open' => false, 'reason' => 'closed']);
        }

        return $this->noStore(WidgetStatus::payload(
            shop: $shop,
            settings: $settings,
            window: $window,
            reason: $reason,
            next: $next,
            test: $test,
            loggedIn: $request->attributes->get('logged_in_customer_id') !== null,
            stats: $stats,
            owedRoll: $owedRoll,
        ));
    }

    public function roll(Request $request, RollService $rolls): JsonResponse
    {
        $customerId = $request->attributes->get('logged_in_customer_id');
        // Accept the widest range here; RollService re-validates against the shop's dice_count.
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'picked_number' => ['required', 'integer', 'between:1,12'],
            'lines' => ['required', 'array', 'min:1', 'max:'.config('shopify.roll.max_cart_lines')],
            'lines.*.variant_id' => ['required'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('shopify.roll.max_line_quantity')],
            'cart_token' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'preview' => ['nullable', 'string', 'max:100'],
        ]);

        $shop = $this->shop($request);
        $ip = $request->attributes->get('customer_ip');

        if (! $customerId && empty($data['email']) && ! $ip) {
            return $this->noStore([
                'error' => 'identity',
                'message' => 'We could not verify this connection. Please try again.',
            ], 422);
        }

        try {
            $result = $rolls->roll($shop, new RollInput(
                email: $data['email'] ?? null,
                pickedNumber: (int) $data['picked_number'],
                lines: $data['lines'],
                ip: $ip,
                shopifyCustomerId: $customerId,
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
