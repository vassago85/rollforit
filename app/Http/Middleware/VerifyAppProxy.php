<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Shopify\AppProxySignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Shopify app proxy signature on every storefront request.
 *
 * Usage: `proxy` for read endpoints, `proxy:once` to also reject a replayed signature.
 */
class VerifyAppProxy
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $raw = (string) $request->server('QUERY_STRING', '');
        $params = AppProxySignature::parse($raw);
        $signature = $params['signature'][0] ?? null;

        if (! $signature || ! AppProxySignature::verify($raw, (string) config('shopify.api_secret'))) {
            return new JsonResponse(['message' => 'Invalid signature.'], 401);
        }

        $timestamp = (int) ($params['timestamp'][0] ?? 0);
        if (abs(time() - $timestamp) > (int) config('shopify.proxy.max_age_seconds')) {
            return new JsonResponse(['message' => 'Request expired. Please reload the page.'], 401);
        }

        if ($mode === 'once') {
            $fresh = Cache::add('proxy-signature:'.$signature, 1, (int) config('shopify.proxy.max_age_seconds') * 2);
            if (! $fresh) {
                return new JsonResponse(['message' => 'This request was already used. Please try again.'], 409);
            }
        }

        $shop = Shop::where('domain', strtolower($params['shop'][0] ?? ''))->first();
        if (! $shop || ! $shop->isInstalled()) {
            return new JsonResponse(['open' => false, 'message' => 'Shop not installed.'], 404);
        }

        $request->attributes->set('shop', $shop);
        $request->attributes->set('customer_ip', self::customerIp($request));
        $request->attributes->set('path_prefix', $params['path_prefix'][0] ?? null);

        return $next($request);
    }

    /**
     * Shopify sets X-Forwarded-For to the customer's IP. Reverse proxies in front
     * of the app append their own hops, so the customer is the leftmost entry.
     */
    public static function customerIp(Request $request): ?string
    {
        $forwarded = (string) $request->headers->get('X-Forwarded-For', '');
        $first = trim(explode(',', $forwarded)[0] ?? '');

        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }

        return $request->ip();
    }
}
