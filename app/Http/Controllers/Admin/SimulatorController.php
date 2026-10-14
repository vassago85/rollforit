<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\Exceptions\RollRejected;
use App\Promotion\RollSimulator;
use App\Promotion\SettingsService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-admin "Test lab": runs the full roll decision in memory so merchants can
 * sanity-check caps, copy, and the result screen without touching live data.
 */
class SimulatorController extends Controller
{
    public function __construct(private readonly SettingsService $settingsService) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();

        return response()->json([
            'shop' => [
                'domain' => $shop->domain,
                'currency' => $shop->currency,
                'symbol' => Money::symbolFor($shop->currency),
            ],
            'settings' => [
                'min_order' => $settings->min_order !== null ? (int) $settings->min_order : null,
                'min_order_formatted' => Money::format($settings->min_order, Money::symbolFor($shop->currency)),
                'max_payout' => $settings->max_payout !== null ? (int) $settings->max_payout : null,
                'max_payout_formatted' => Money::format($settings->max_payout, Money::symbolFor($shop->currency)),
                'max_payout_percent' => $settings->max_payout_percent !== null ? (int) $settings->max_payout_percent : null,
                'odds_n' => (int) $settings->odds_n,
                'odds_mode' => $settings->odds_mode,
            ],
            'warnings' => SettingsService::warnings($settings, Money::symbolFor($shop->currency)),
            'blockers' => $settings->enableBlockers(),
        ]);
    }

    public function roll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart_value' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'outcome' => ['required', 'in:win,loss,random'],
            'picked_number' => ['required', 'integer', 'between:1,10'],
            'channel' => ['required', 'in:'.Roll::CHANNEL_ONLINE.','.Roll::CHANNEL_POS],
        ]);

        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();

        try {
            $payload = RollSimulator::run(
                shop: $shop,
                settings: $settings,
                cartSubtotalCents: (int) Money::toCents($data['cart_value']),
                outcome: $data['outcome'],
                pickedNumber: (int) $data['picked_number'],
                channel: $data['channel'],
            );
        } catch (RollRejected $e) {
            return response()->json([
                'error' => $e->reason,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json($payload);
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
