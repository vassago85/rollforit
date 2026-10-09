<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Roll;
use App\Models\Shop;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopsController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $shops = Shop::with('settings')
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('domain', 'ilike', '%'.$search.'%')
                        ->orWhere('name', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%');
                });
            })
            ->orderByDesc('installed_at')
            ->paginate(25)
            ->withQueryString();

        $since = CarbonImmutable::now()->subDays(7)->toDateString();
        $rollCounts = Roll::where('is_test', false)
            ->where('promo_date', '>=', $since)
            ->whereIn('shop_id', $shops->pluck('id'))
            ->selectRaw('shop_id, count(*) as rolls, count(*) filter (where won) as winners, coalesce(sum(payout) filter (where won), 0) as payout')
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        return view('op.shops.index', [
            'shops' => $shops,
            'rollCounts' => $rollCounts,
            'search' => $search,
        ]);
    }

    public function show(Shop $shop): View
    {
        $settings = $shop->settingsOrCreate();
        $symbol = Money::symbolFor($shop->currency ?: 'ZAR');

        $totals = Roll::where('shop_id', $shop->id)
            ->where('is_test', false)
            ->selectRaw('count(*) as rolls, count(*) filter (where won) as winners, coalesce(sum(payout) filter (where won), 0) as payout')
            ->first();

        $today = Roll::where('shop_id', $shop->id)
            ->where('is_test', false)
            ->where('promo_date', now()->toDateString())
            ->selectRaw('count(*) as rolls, count(*) filter (where won) as winners, coalesce(sum(payout) filter (where won), 0) as payout')
            ->first();

        $recentDays = Roll::where('shop_id', $shop->id)
            ->where('is_test', false)
            ->select('promo_date')
            ->selectRaw('count(*) as rolls, count(*) filter (where won) as winners, coalesce(sum(payout) filter (where won), 0) as payout')
            ->groupBy('promo_date')
            ->orderByDesc('promo_date')
            ->limit(14)
            ->get();

        return view('op.shops.show', [
            'shop' => $shop,
            'settings' => $settings,
            'symbol' => $symbol,
            'totals' => [
                'rolls' => (int) ($totals->rolls ?? 0),
                'winners' => (int) ($totals->winners ?? 0),
                'payout' => (int) ($totals->payout ?? 0),
                'payout_formatted' => Money::format((int) ($totals->payout ?? 0), $symbol),
            ],
            'today' => [
                'rolls' => (int) ($today->rolls ?? 0),
                'winners' => (int) ($today->winners ?? 0),
                'payout' => (int) ($today->payout ?? 0),
                'payout_formatted' => Money::format((int) ($today->payout ?? 0), $symbol),
            ],
            'recentDays' => $recentDays->map(fn ($row) => [
                'promo_date' => $row->promo_date,
                'rolls' => (int) $row->rolls,
                'winners' => (int) $row->winners,
                'payout_formatted' => Money::format((int) $row->payout, $symbol),
            ]),
        ]);
    }
}
