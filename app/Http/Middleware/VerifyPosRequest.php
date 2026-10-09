<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Shopify\PosSignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * HuntexPos → Roll for It auth.
 *
 * The till sends:
 *   X-RFI-Shop:       shop domain
 *   X-RFI-Timestamp:  unix seconds
 *   X-RFI-Nonce:      opaque random (replay-rejected via cache)
 *   X-RFI-Signature:  hex HMAC-SHA256 of "<timestamp>.<nonce>.<raw body>" with the per-shop POS secret
 *
 * The secret itself is stored only as a hash (ShopSettings::pos_secret_hash); the
 * till keeps the plaintext in its own .env.
 */
class VerifyPosRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = strtolower((string) $request->headers->get('X-RFI-Shop', ''));
        $timestamp = (string) $request->headers->get('X-RFI-Timestamp', '');
        $nonce = (string) $request->headers->get('X-RFI-Nonce', '');
        $signature = (string) $request->headers->get('X-RFI-Signature', '');

        if ($domain === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return self::reject('Missing POS auth headers.');
        }

        if (! PosSignature::withinWindow($timestamp)) {
            return self::reject('POS request is outside the signing window.');
        }

        // Nonce length cap keeps the cache keyspace bounded; also stops a very long nonce
        // from DOSing Cache::add on some drivers.
        if (strlen($nonce) > 128) {
            return self::reject('POS nonce is too long.');
        }

        $shop = Shop::where('domain', $domain)->first();
        if (! $shop || ! $shop->isInstalled()) {
            return self::reject('Unknown shop.', 404);
        }

        $settings = $shop->settingsOrCreate();
        if (! $settings->pos_enabled || ! $settings->pos_secret) {
            return self::reject('POS integration is not enabled for this shop.', 403);
        }

        if (! PosSignature::verify($timestamp, $nonce, (string) $request->getContent(), $signature, $settings->pos_secret)) {
            return self::reject('Bad POS signature.');
        }

        $cacheKey = "pos-nonce:{$shop->id}:{$nonce}";
        if (! Cache::add($cacheKey, 1, PosSignature::WINDOW_SECONDS * 2)) {
            return self::reject('This POS request was already used.', 409);
        }

        $request->attributes->set('shop', $shop);
        $request->attributes->set('customer_ip', VerifyAppProxy::customerIp($request));

        return $next($request);
    }

    private static function reject(string $message, int $status = 401): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
