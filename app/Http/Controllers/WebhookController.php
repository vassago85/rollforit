<?php

namespace App\Http\Controllers;

use App\Models\PrivacyRequest;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\EmailHasher;
use App\Promotion\IdentityHasher;
use App\Promotion\RedemptionTracker;
use App\Promotion\RollFulfillment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private readonly RollFulfillment $fulfillment,
        private readonly RedemptionTracker $redemption,
    ) {}

    public function handle(Request $request): Response
    {
        $topic = (string) $request->header('X-Shopify-Topic', '');
        $domain = strtolower((string) $request->header('X-Shopify-Shop-Domain', $request->input('shop_domain', '')));
        $shop = Shop::where('domain', $domain)->first();

        if (! $shop) {
            return response('', 200);
        }

        match ($topic) {
            'app/uninstalled' => $this->uninstalled($shop),
            'orders/paid' => $this->orderPaid($shop, $request),
            'orders/cancelled' => $this->orderCancelled($shop, $request),
            'customers/data_request' => $this->dataRequest($shop, $request),
            'customers/redact' => $this->customerRedact($shop, $request),
            'shop/redact' => $this->shopRedact($shop),
            default => Log::info('Unhandled webhook topic', ['topic' => $topic, 'shop' => $domain]),
        };

        return response('', 200);
    }

    /**
     * A paid order unlocks the roller's next roll. We ignore test / cancelled orders;
     * cancelled orders are handled by their own topic, and tests must never affect state.
     */
    private function orderPaid(Shop $shop, Request $request): void
    {
        if ($request->boolean('test') || $request->input('cancelled_at')) {
            return;
        }

        $orderId = (string) $request->input('admin_graphql_api_id', '');
        if ($orderId === '') {
            return;
        }

        $customerId = trim((string) $request->input('customer.id', ''));
        $customerHash = $customerId !== '' ? IdentityHasher::hashShopifyCustomerId($customerId) : null;
        $email = trim((string) ($request->input('customer.email') ?? $request->input('email', '')));
        $emailHash = $email !== '' ? EmailHasher::hash($email) : null;
        $ip = (string) $request->input('browser_ip', '');
        $ip = $ip !== '' ? $ip : null;

        $placedAt = CarbonImmutable::parse((string) ($request->input('created_at') ?? now()->toIso8601String()));

        $roll = $this->fulfillment->markOrderPaid($shop, $orderId, $emailHash, $customerHash, $ip, $placedAt);

        // Independently check if this order used a Roll4It discount code, so a
        // merchant's "Rolls today" view flips the winning row from "Not redeemed
        // yet" to "Redeemed R500" within seconds of the shopper checking out.
        // This is separate from fulfillment: the roller can have been unblocked
        // by any paid order, while redemption only fires when THIS order's cart
        // actually contained the winning code.
        $codes = $this->discountCodesFrom($request);
        $discountCents = Money::toCents((string) ($request->input('total_discounts') ?? '0')) ?? 0;
        $redeemed = $this->redemption->markOrderRedemption($shop, $orderId, $codes, $discountCents, $placedAt);

        Log::info('Roll4It orders/paid processed', [
            'shop' => $shop->domain,
            'order' => $orderId,
            'matched_roll' => $roll?->id,
            'redeemed_roll' => $redeemed?->id,
        ]);
    }

    /**
     * Shopify sends discount_codes as `[{code: "FOO", amount: "...", type: "..."}, ...]`
     * on orders/* webhooks. Flatten to a plain list of code strings for the tracker.
     *
     * @return list<string>
     */
    private function discountCodesFrom(Request $request): array
    {
        $raw = (array) $request->input('discount_codes', []);
        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            } elseif (is_array($entry) && isset($entry['code'])) {
                $out[] = (string) $entry['code'];
            }
        }

        return $out;
    }

    private function orderCancelled(Shop $shop, Request $request): void
    {
        $orderId = (string) $request->input('admin_graphql_api_id', '');
        $cleared = $this->fulfillment->markOrderCancelled($shop, $orderId);

        if ($cleared > 0) {
            Log::info('Roll4It orders/cancelled re-locked rolls', [
                'shop' => $shop->domain,
                'order' => $orderId,
                'rolls' => $cleared,
            ]);
        }
    }

    private function uninstalled(Shop $shop): void
    {
        DB::transaction(function () use ($shop) {
            $shop->forceFill([
                'access_token' => null,
                'access_token_expires_at' => null,
                'refresh_token' => null,
                'refresh_token_expires_at' => null,
                'uninstalled_at' => now(),
            ])->save();

            $shop->settings()->update(['enabled' => false]);
        });
    }

    private function dataRequest(Shop $shop, Request $request): void
    {
        $matched = $this->rollsForCustomer($shop, $request)->count();

        PrivacyRequest::create([
            'shop_id' => $shop->id,
            'topic' => 'customers/data_request',
            'shopify_customer_id' => (string) $request->input('customer.id', ''),
            'matched_rolls' => $matched,
            'details' => [
                'data_request_id' => $request->input('data_request.id'),
                'orders_requested' => $request->input('orders_requested', []),
                'stored_fields' => 'Roll records hold a keyed hash of the email (not the address), IP address (kept up to 90 days), cart totals, outcome and discount code.',
            ],
        ]);
    }

    private function customerRedact(Shop $shop, Request $request): void
    {
        $deleted = $this->rollsForCustomer($shop, $request)->delete();

        PrivacyRequest::create([
            'shop_id' => $shop->id,
            'topic' => 'customers/redact',
            'shopify_customer_id' => (string) $request->input('customer.id', ''),
            'matched_rolls' => $deleted,
            'details' => ['orders_to_redact' => $request->input('orders_to_redact', [])],
        ]);
    }

    private function shopRedact(Shop $shop): void
    {
        $shop->delete();
    }

    /**
     * @return Builder<Roll>
     */
    private function rollsForCustomer(Shop $shop, Request $request)
    {
        $email = (string) $request->input('customer.email', '');
        $customerId = (string) $request->input('customer.id', '');
        $customerHash = $customerId !== '' ? IdentityHasher::hashShopifyCustomerId($customerId) : null;

        return Roll::where('shop_id', $shop->id)->where(function ($q) use ($email, $customerHash) {
            if ($email !== '') {
                $q->orWhere('email_hash', EmailHasher::hash($email));
            }
            if ($customerHash) {
                $q->orWhere('shopify_customer_hash', $customerHash);
            }
        });
    }
}
