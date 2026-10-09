<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Promotion\PreviewLinks;
use App\Promotion\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
