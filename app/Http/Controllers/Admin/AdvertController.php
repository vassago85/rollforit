<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Promotion\AdvertCopyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvertController extends Controller
{
    public function __construct(private readonly AdvertCopyService $adverts) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();
        $copy = $this->adverts->generate($shop, $settings);

        return response()->json([
            'blocks' => $copy['blocks'],
            'missing' => $copy['missing'],
            'changes' => $this->adverts->changesSinceAck($shop, $settings),
            'acknowledged' => is_array($settings->advert_ack_snapshot) && $settings->advert_ack_snapshot !== [],
        ]);
    }

    public function acknowledge(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $this->adverts->acknowledge($shop, $shop->settingsOrCreate());

        return $this->show($request);
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
