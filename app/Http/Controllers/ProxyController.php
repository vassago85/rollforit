<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Promotion\DayStats;
use App\Promotion\EmailVerification;
use App\Promotion\Exceptions\PayoutGuardViolation;
use App\Promotion\Exceptions\RollRejected;
use App\Promotion\Exceptions\VerificationRejected;
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
                // Still surface today's winners + total paid: when the window has
                // closed for the day (either sold out earlier or past end_time),
                // the customer should see "R4,500 given away today, 3 winners".
                $stats = DayStats::for($shop->id, $window->promoDate());
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

    public function roll(Request $request, RollService $rolls, EmailVerification $verification): JsonResponse
    {
        $customerId = $request->attributes->get('logged_in_customer_id');
        // Accept the widest range here; RollService re-validates against the shop's dice_count.
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'picked_number' => ['required', 'integer', 'between:1,1000'],
            'lines' => ['required', 'array', 'min:1', 'max:'.config('shopify.roll.max_cart_lines')],
            'lines.*.variant_id' => ['required'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('shopify.roll.max_line_quantity')],
            'cart_token' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'preview' => ['nullable', 'string', 'max:100'],
        ]);

        $shop = $this->shop($request);
        $ip = $request->attributes->get('customer_ip');
        $settings = $shop->settingsOrCreate();
        $window = new PromoWindow($settings, $shop->timezone);
        $isTest = PreviewLinks::isValid($settings, $data['preview'] ?? null);

        // A signed-in Shopify customer is already verified by Shopify; a merchant
        // in test mode bypasses the challenge; everyone else must present an email
        // we've just confirmed via Mailgun.
        if (! $customerId && ! $isTest) {
            if (empty($data['email'])) {
                return $this->noStore([
                    'error' => 'email_required',
                    'message' => 'Enter your email to roll.',
                ], 422);
            }
            if (! $verification->isVerified($shop, $data['email'], $window->promoDate())) {
                return $this->noStore([
                    'error' => 'email_not_verified',
                    'message' => 'Please confirm your email before rolling.',
                ], 403);
            }
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

    public function take(Request $request, RollService $rolls, EmailVerification $verification): JsonResponse
    {
        return $this->decide($request, $rolls, $verification, risk: false);
    }

    public function bet(Request $request, RollService $rolls, EmailVerification $verification): JsonResponse
    {
        return $this->decide($request, $rolls, $verification, risk: true);
    }

    private function decide(Request $request, RollService $rolls, EmailVerification $verification, bool $risk): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'preview' => ['nullable', 'string', 'max:100'],
            'card' => ['nullable', 'integer', 'in:1,2'],
        ]);

        $shop = $this->shop($request);
        $customerId = $request->attributes->get('logged_in_customer_id');
        $settings = $shop->settingsOrCreate();
        $window = new PromoWindow($settings, $shop->timezone);
        $isTest = PreviewLinks::isValid($settings, $data['preview'] ?? null);

        if (! $customerId && ! $isTest) {
            if (empty($data['email'])) {
                return $this->noStore(['error' => 'email_required', 'message' => 'Enter your email to roll.'], 422);
            }
            if (! $verification->isVerified($shop, $data['email'], $window->promoDate())) {
                return $this->noStore(['error' => 'email_not_verified', 'message' => 'Please confirm your email before rolling.'], 403);
            }
        }

        try {
            $input = new RollInput(
                email: $data['email'] ?? null,
                pickedNumber: 1,
                lines: [],
                ip: $request->attributes->get('customer_ip'),
                shopifyCustomerId: $customerId,
                previewToken: $data['preview'] ?? null,
            );
            $result = $risk ? $rolls->bet($shop, $input) : $rolls->take($shop, $input);
        } catch (RollRejected $e) {
            return $this->noStore(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);
        } catch (PayoutGuardViolation) {
            return $this->noStore([
                'error' => 'prize_unavailable',
                'message' => 'Sorry, we could not issue your prize. Please contact the store.',
            ], 500);
        } catch (ShopifyApiException $e) {
            Log::error('Roll decision failed on Shopify API', ['shop' => $shop->domain, 'error' => $e->getMessage()]);

            return $this->noStore(['error' => 'unavailable', 'message' => 'Something went wrong. Please try again.'], 503);
        }

        return $this->noStore($result);
    }

    /**
     * Issue a one-time 6-digit confirmation code via Mailgun for the given email.
     * Called by the storefront widget once a guest shopper types their email.
     */
    public function sendVerifyCode(Request $request, EmailVerification $verification): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
        ]);

        $shop = $this->shop($request);
        $ip = $request->attributes->get('customer_ip');

        // Signed-in Shopify customers never need to confirm; short-circuit so a
        // misbehaving widget can't spam the mailer.
        if ($request->attributes->get('logged_in_customer_id')) {
            return $this->noStore(['sent' => false, 'reason' => 'logged_in']);
        }

        try {
            $result = $verification->sendCode($shop, $data['email'], $ip);
        } catch (VerificationRejected $e) {
            return $this->noStore(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);
        }

        return $this->noStore([
            'sent' => true,
            'expires_at' => $result['expires_at'],
            'cooldown_seconds' => $result['cooldown_seconds'],
        ]);
    }

    /**
     * Confirm a 6-digit code from the Mailgun email. On success the shopper is
     * verified for the current promo day; the widget may now call /roll.
     */
    public function verifyCode(Request $request, EmailVerification $verification): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $shop = $this->shop($request);
        $ip = $request->attributes->get('customer_ip');
        $window = new PromoWindow($shop->settingsOrCreate(), $shop->timezone);

        try {
            $verification->verifyCode($shop, $data['email'], $data['code'], $window->promoDate(), $ip);
        } catch (VerificationRejected $e) {
            return $this->noStore(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);
        }

        return $this->noStore([
            'verified' => true,
            'email' => $data['email'],
            'promo_date' => $window->promoDate(),
        ]);
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
