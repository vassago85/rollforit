<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhooks (including the mandatory compliance topics) must answer 401 on a bad HMAC.
 */
class VerifyWebhookHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.api_secret');
        $header = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if ($secret === '' || $header === '' || ! hash_equals($expected, $header)) {
            return new JsonResponse(['message' => 'Invalid HMAC.'], 401);
        }

        return $next($request);
    }
}
