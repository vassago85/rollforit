<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Shopify\Exceptions\InvalidSessionToken;
use App\Shopify\Exceptions\ShopifyApiException;
use App\Shopify\SessionTokenVerifier;
use App\Shopify\ShopInfoSync;
use App\Shopify\TokenExchanger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates embedded-admin API calls with the App Bridge ID token, and
 * performs token exchange the first time a shop is seen (Shopify-managed install).
 */
class VerifySessionToken
{
    public function __construct(
        private readonly SessionTokenVerifier $verifier,
        private readonly TokenExchanger $exchanger,
        private readonly ShopInfoSync $info,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return $this->unauthorized('Missing ID token.');
        }

        try {
            $identity = $this->verifier->verify($token);
        } catch (InvalidSessionToken $e) {
            return $this->unauthorized($e->getMessage());
        }

        $shop = Shop::where('domain', $identity['shop'])->first();

        if (! $shop || ! $shop->isInstalled()) {
            try {
                $shop = $this->exchanger->exchange($identity['shop'], $token);
                $this->info->sync($shop);
                $shop->settingsOrCreate();
            } catch (InvalidSessionToken $e) {
                return $this->unauthorized($e->getMessage());
            } catch (ShopifyApiException $e) {
                Log::error('Token exchange failed', ['shop' => $identity['shop'], 'error' => $e->getMessage()]);

                return new JsonResponse(['message' => 'Could not connect to Shopify. Please reload.'], 502);
            }
        } elseif ($this->shouldRefreshShopInfo($shop)) {
            try {
                $this->info->sync($shop);
            } catch (ShopifyApiException $e) {
                Log::warning('Shop info refresh failed', ['shop' => $shop->domain, 'error' => $e->getMessage()]);
            }
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }

    /**
     * Re-sync name, timezone, currency and primary domain when the cached
     * record looks stale. Shopify only sends this on first install today, so
     * a store that later moves from New York to Pretoria would keep the old
     * window forever.
     */
    private function shouldRefreshShopInfo(Shop $shop): bool
    {
        return $shop->updated_at === null || $shop->updated_at->lt(now()->subHour());
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], 401, [
            'X-Shopify-Retry-Invalid-Session-Request' => '1',
        ]);
    }
}
