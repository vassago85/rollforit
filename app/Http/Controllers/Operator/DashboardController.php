<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Roll;
use App\Models\Shop;
use App\Support\Money;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $today = now()->toDateString();

        $installed = Shop::whereNotNull('access_token')->whereNull('uninstalled_at')->count();
        $uninstalled = Shop::whereNotNull('uninstalled_at')->count();
        $newToday = Shop::whereDate('installed_at', $today)->count();

        $todayRollsAgg = Roll::where('promo_date', $today)
            ->where('is_test', false)
            ->selectRaw('count(*) as rolls, count(*) filter (where won) as winners, coalesce(sum(payout) filter (where won), 0) as payout')
            ->first();

        $recentShops = Shop::orderByDesc('installed_at')
            ->limit(5)
            ->get(['id', 'domain', 'name', 'installed_at', 'uninstalled_at']);

        return view('op.dashboard', [
            'metrics' => [
                'installed' => $installed,
                'uninstalled' => $uninstalled,
                'new_today' => $newToday,
                'rolls_today' => (int) ($todayRollsAgg->rolls ?? 0),
                'winners_today' => (int) ($todayRollsAgg->winners ?? 0),
                'payout_today' => (int) ($todayRollsAgg->payout ?? 0),
                'payout_today_formatted' => Money::format((int) ($todayRollsAgg->payout ?? 0)),
            ],
            'recentShops' => $recentShops,
        ]);
    }
}
