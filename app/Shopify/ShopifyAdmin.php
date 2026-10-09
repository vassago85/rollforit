<?php

namespace App\Shopify;

use App\Models\Shop;
use App\Shopify\Exceptions\ShopifyApiException;
use Illuminate\Support\Facades\Http;

/**
 * Thin GraphQL Admin API client pinned to the configured API version.
 */
class ShopifyAdmin
{
    public function __construct(private readonly TokenExchanger $tokens) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> The `data` member of the response.
     */
    public function graphql(Shop $shop, string $query, array $variables = []): array
    {
        $shop = $this->tokens->refreshIfNeeded($shop);
        $url = sprintf('https://%s/admin/api/%s/graphql.json', $shop->domain, config('shopify.api_version'));

        for ($attempt = 1; ; $attempt++) {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $shop->access_token])
                ->asJson()
                ->acceptJson()
                ->timeout(config('shopify.http_timeout'))
                ->post($url, ['query' => $query, 'variables' => (object) $variables]);

            if (! $response->successful()) {
                throw new ShopifyApiException("Admin API HTTP {$response->status()}", [], $response->status());
            }

            $body = $response->json();
            $errors = $body['errors'] ?? [];

            if ($errors && $this->isThrottled($errors) && $attempt < 3) {
                usleep(1_000_000 * $attempt);

                continue;
            }

            if ($errors) {
                throw new ShopifyApiException('Admin API returned errors: '.json_encode($errors), $errors);
            }

            return $body['data'] ?? [];
        }
    }

    /**
     * Throws when a mutation payload carries userErrors.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertNoUserErrors(array $payload, string $context): void
    {
        $userErrors = $payload['userErrors'] ?? [];
        if ($userErrors) {
            $messages = implode('; ', array_map(fn ($e) => $e['message'] ?? 'Unknown error', $userErrors));
            throw new ShopifyApiException("{$context}: {$messages}", $userErrors);
        }
    }

    /**
     * @param  array<int, mixed>  $errors
     */
    private function isThrottled(array $errors): bool
    {
        foreach ($errors as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }
}
