<?php

namespace App\Http\Middleware;

use App\Shopify\ShopDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Embedded admin pages may only be framed by Shopify admin and the shop itself.
 */
class EmbeddedAppHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = (string) $request->query('shop', '');
        $ancestors = ShopDomain::isValid($shop)
            ? "https://{$shop} https://admin.shopify.com"
            : 'https://*.myshopify.com https://admin.shopify.com';

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors};");

        return $response;
    }
}
