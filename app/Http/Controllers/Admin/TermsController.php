<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Models\TermsVersion;
use App\Promotion\TermsService;
use App\Shopify\Exceptions\ShopifyApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsController extends Controller
{
    public function __construct(private readonly TermsService $terms) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        return response()->json($this->payload($shop, $shop->settingsOrCreate()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'terms_mode' => ['required', 'in:'.ShopSettings::TERMS_GENERATED.','.ShopSettings::TERMS_OWN],
            'own_page_id' => ['nullable', 'required_if:terms_mode,own', 'string', 'starts_with:gid://shopify/Page/'],
        ]);

        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();

        if ($settings->terms_mode !== $data['terms_mode'] || $settings->own_page_id !== ($data['own_page_id'] ?? null)) {
            $settings->forceFill([
                'terms_mode' => $data['terms_mode'],
                'own_page_id' => $data['own_page_id'] ?? null,
                'terms_url' => null,
                'terms_published_at' => null,
                'enabled' => false,
            ])->save();
        }

        return response()->json($this->payload($shop, $settings));
    }

    public function publish(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        try {
            $settings = $this->terms->publish($shop, $shop->settingsOrCreate());
        } catch (ShopifyApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->payload($shop, $settings));
    }

    public function reviewed(Request $request): JsonResponse
    {
        $data = $request->validate(['reviewed' => ['required', 'boolean']]);
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();
        $settings->forceFill(['terms_reviewed' => $data['reviewed']])->save();

        return response()->json($this->payload($shop, $settings));
    }

    public function pages(Request $request): JsonResponse
    {
        try {
            return response()->json(['pages' => $this->terms->listPages($this->shop($request))]);
        } catch (ShopifyApiException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function version(Request $request, int $version): JsonResponse
    {
        $row = TermsVersion::where('shop_id', $this->shop($request)->id)->where('version', $version)->firstOrFail();

        return response()->json([
            'version' => $row->version,
            'source' => $row->source,
            'published_at' => $row->published_at?->toIso8601String(),
            'content' => $row->content,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Shop $shop, ShopSettings $settings): array
    {
        $missing = $this->terms->missingFields($settings);

        return [
            'terms_mode' => $settings->terms_mode,
            'own_page_id' => $settings->own_page_id,
            'terms_url' => $settings->terms_url,
            'published' => $settings->hasPublishedTerms(),
            'published_at' => $settings->terms_published_at?->toIso8601String(),
            'reviewed' => (bool) $settings->terms_reviewed,
            'missing' => $missing,
            'preview_html' => $settings->terms_mode === ShopSettings::TERMS_GENERATED && $missing === []
                ? $this->terms->render($shop, $settings) : null,
            'versions' => TermsVersion::where('shop_id', $shop->id)->orderByDesc('version')->limit(50)
                ->get(['version', 'source', 'published_at'])
                ->map(fn (TermsVersion $v) => [
                    'version' => $v->version,
                    'source' => $v->source,
                    'published_at' => $v->published_at?->toIso8601String(),
                ])->all(),
        ];
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
