<?php

namespace App\Http\Controllers;

use App\Models\PrivacyRequest;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\EmailHasher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
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
            'customers/data_request' => $this->dataRequest($shop, $request),
            'customers/redact' => $this->customerRedact($shop, $request),
            'shop/redact' => $this->shopRedact($shop),
            default => Log::info('Unhandled webhook topic', ['topic' => $topic, 'shop' => $domain]),
        };

        return response('', 200);
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
        $email = (string) $request->input('customer.email', '');
        $matched = $email !== '' ? Roll::where('shop_id', $shop->id)->where('email_hash', EmailHasher::hash($email))->count() : 0;

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
        $email = (string) $request->input('customer.email', '');
        $deleted = $email !== ''
            ? Roll::where('shop_id', $shop->id)->where('email_hash', EmailHasher::hash($email))->delete()
            : 0;

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
}
