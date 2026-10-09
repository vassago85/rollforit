<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Promotion\PreviewLinks;
use App\Promotion\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $service) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        return response()->json($this->service->present($shop, $shop->settingsOrCreate()));
    }

    public function update(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $result = $this->service->update($shop, $shop->settingsOrCreate(), $request->all());

        return response()->json($this->service->present($shop, $result['settings']) + [
            'terms_error' => $result['terms_error'],
        ]);
    }

    /**
     * Generate (or rotate) the shop's POS signing secret. Shown to the merchant exactly
     * once — only a hash is persisted — so they must copy it into the till right away.
     */
    public function rotatePosSecret(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();

        $secret = Str::random(48);
        $settings->forceFill([
            'pos_secret' => $secret,
            'pos_secret_last_four' => substr($secret, -4),
            'pos_secret_rotated_at' => now(),
            'pos_enabled' => true,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'last_four' => $settings->pos_secret_last_four,
            'rotated_at' => $settings->pos_secret_rotated_at->toIso8601String(),
            'shop_domain' => $shop->domain,
            'base_url' => rtrim((string) config('app.url'), '/').'/api/pos',
        ]);
    }

    public function disablePos(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();
        $settings->forceFill([
            'pos_enabled' => false,
            'pos_secret' => null,
            'pos_secret_last_four' => null,
            'pos_secret_rotated_at' => null,
        ])->save();

        return response()->json(['disabled' => true]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['outcome' => ['required', 'in:win,loss']]);
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();

        if (! $settings->min_order || ! $settings->max_payout) {
            return response()->json(['message' => 'Set a minimum order and maximum payout first.'], 422);
        }

        return response()->json(PreviewLinks::issue($shop, $settings, $data['outcome']));
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
