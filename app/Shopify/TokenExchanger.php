<?php

namespace App\Shopify;

use App\Models\Shop;
use App\Shopify\Exceptions\InvalidSessionToken;
use App\Shopify\Exceptions\ShopifyApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Token exchange for expiring offline access tokens, and refresh-token rotation.
 */
class TokenExchanger
{
    public function exchange(string $shopDomain, string $idToken): Shop
    {
        $response = Http::asJson()
            ->timeout(config('shopify.http_timeout'))
            ->post("https://{$shopDomain}/admin/oauth/access_token", [
                'client_id' => config('shopify.api_key'),
                'client_secret' => config('shopify.api_secret'),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token' => $idToken,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                'expiring' => 1,
            ]);

        if ($response->status() === 400) {
            throw new InvalidSessionToken('Shopify rejected the ID token during token exchange.');
        }
        if (! $response->successful()) {
            throw new ShopifyApiException('Token exchange failed.', [], $response->status());
        }

        $shop = Shop::firstOrNew(['domain' => $shopDomain]);
        $this->fill($shop, $response->json());
        $shop->installed_at ??= now();
        $shop->uninstalled_at = null;
        $shop->save();

        return $shop;
    }

    public function refreshIfNeeded(Shop $shop): Shop
    {
        if (! $shop->accessTokenNeedsRefresh()) {
            return $shop;
        }

        return DB::transaction(function () use ($shop) {
            /** @var Shop $locked */
            $locked = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            if (! $locked->accessTokenNeedsRefresh()) {
                return $locked;
            }

            return $this->refresh($locked);
        });
    }

    public function refresh(Shop $shop): Shop
    {
        if (! $shop->refresh_token) {
            throw new ShopifyApiException('No refresh token stored; the merchant must open the app again.');
        }

        $response = Http::asJson()
            ->timeout(config('shopify.http_timeout'))
            ->post("https://{$shop->domain}/admin/oauth/access_token", [
                'client_id' => config('shopify.api_key'),
                'client_secret' => config('shopify.api_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $shop->refresh_token,
            ]);

        if (! $response->successful()) {
            throw new ShopifyApiException('Refreshing the offline access token failed.', [], $response->status());
        }

        $this->fill($shop, $response->json());
        $shop->save();

        return $shop;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Shop $shop, array $data): void
    {
        $shop->access_token = $data['access_token'];
        $shop->scopes = $data['scope'] ?? $shop->scopes;

        $expiresIn = (int) ($data['expires_in'] ?? 0);
        $shop->access_token_expires_at = $expiresIn > 0 ? now()->addSeconds($expiresIn) : null;

        if (! empty($data['refresh_token'])) {
            $shop->refresh_token = $data['refresh_token'];
            $refreshIn = (int) ($data['refresh_token_expires_in'] ?? 0);
            $shop->refresh_token_expires_at = $refreshIn > 0 ? now()->addSeconds($refreshIn) : null;
        }
    }
}
