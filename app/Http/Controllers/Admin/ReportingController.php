<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\PrivacyRequest;
use App\Models\Roll;
use App\Models\Shop;
use App\Promotion\DayStats;
use App\Promotion\PromoMetricsService;
use App\Promotion\PromoWindow;
use App\Shopify\Exceptions\ShopifyApiException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    public function __construct(private readonly PromoMetricsService $metrics) {}

    public function index(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $shop->settingsOrCreate();
        $symbol = Money::symbolFor($shop->currency);
        $window = new PromoWindow($settings, $shop->timezone);
        $today = $window->promoDate();
        $stats = DayStats::for($shop->id, $today);

        $block = $settings->isGuaranteed()
            ? Block::where('shop_id', $shop->id)->whereNull('completed_at')->orderByDesc('id')->first()
            : null;

        $history = Roll::where('shop_id', $shop->id)->where('is_test', false)
            ->select('promo_date')
            ->selectRaw('count(*) as rolls, count(*) filter (where won) as winners, coalesce(sum(payout) filter (where won), 0) as payout')
            ->groupBy('promo_date')->orderByDesc('promo_date')->limit(90)->get()
            ->map(fn ($row) => [
                'promo_date' => CarbonImmutable::parse($row->promo_date)->toDateString(),
                'rolls' => (int) $row->rolls,
                'winners' => (int) $row->winners,
                'payout' => (int) $row->payout,
                'payout_formatted' => Money::format((int) $row->payout, $symbol),
            ]);

        $recent = Roll::where('shop_id', $shop->id)->whereDate('promo_date', $today)
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (Roll $r) => [
                'time' => $r->created_at->setTimezone($shop->timezone)->format('H:i:s'),
                'won' => $r->won,
                'payout_formatted' => $r->won ? Money::format($r->payout, $symbol) : '',
                'cart_formatted' => Money::format($r->cart_subtotal, $symbol),
                'picked' => $r->picked_number,
                'displayed' => $r->displayed_number,
                'code' => $r->discount_code,
                'code_status' => $r->code_status,
                'test' => $r->is_test,
            ]);

        return response()->json([
            'today' => [
                'date' => $today,
                'is_promo_day' => $window->isPromoDay(),
                'open' => $settings->enabled && $settings->canEnable() && $window->isOpen(),
                'rolls' => $stats->rolls,
                'winners' => $stats->winners,
                'payout' => $stats->payout,
                'payout_formatted' => Money::format($stats->payout, $symbol),
                'daily_budget_formatted' => $settings->daily_budget ? Money::format($settings->daily_budget, $symbol) : null,
                'budget_remaining_formatted' => $stats->budgetRemaining($settings) !== null
                    ? Money::format($stats->budgetRemaining($settings), $symbol) : null,
                'budget_used_pct' => $settings->daily_budget ? min(100, round($stats->payout / $settings->daily_budget * 100)) : null,
                'max_winners_per_day' => $settings->max_winners_per_day,
                'closed_reason' => $stats->closedReason($settings),
            ],
            'block' => $settings->isGuaranteed() ? [
                'size' => $block?->size ?? (int) $settings->odds_n,
                'rolls_in_block' => $block ? $block->next_position - 1 : 0,
                'completed_blocks' => Block::where('shop_id', $shop->id)->whereNotNull('completed_at')->count(),
            ] : null,
            'history' => $history,
            'recent' => $recent,
            'privacy_requests' => PrivacyRequest::where('shop_id', $shop->id)->orderByDesc('id')->limit(50)->get()
                ->map(fn (PrivacyRequest $p) => [
                    'topic' => $p->topic,
                    'customer_id' => $p->shopify_customer_id,
                    'matched_rolls' => $p->matched_rolls,
                    'received_at' => $p->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'fresh' => ['nullable', 'boolean'],
        ]);
        $shop = $this->shop($request);

        try {
            $today = $this->metrics->today($shop, (bool) ($data['fresh'] ?? false));
            $todayError = null;
        } catch (ShopifyApiException $e) {
            $today = null;
            $todayError = $e->getMessage();
        }

        return response()->json([
            'today' => $today,
            'today_error' => $todayError,
            'campaign' => $this->metrics->campaign($shop, $data['from'] ?? null, $data['to'] ?? null),
        ]);
    }

    public function recompute(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $shop = $this->shop($request);

        $date = CarbonImmutable::parse($data['date'], $shop->timezone);
        if ($date->lessThan(CarbonImmutable::now($shop->timezone)->subDays(59)->startOfDay())) {
            return response()->json(['message' => 'Shopify only allows reading orders from the last 60 days, so this day can no longer be recomputed.'], 422);
        }

        $closed = $date->addDay()->startOfDay()->isPast();

        try {
            $this->metrics->snapshot($shop, $data['date'], final: $closed);
        } catch (ShopifyApiException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return $this->metrics($request);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $campaign = $this->metrics->campaign($this->shop($request), $data['from'] ?? null, $data['to'] ?? null);

        $columns = ['promo_date', 'orders_count', 'revenue', 'merch_revenue', 'roller_orders_count', 'roller_revenue',
            'winner_orders_count', 'winner_revenue', 'rolls_count', 'winners_count', 'prizes_issued', 'prizes_redeemed',
            'prizes_pct_of_revenue', 'revenue_after_prizes', 'redemption_rate', 'is_final'];
        $moneyColumns = ['revenue', 'merch_revenue', 'roller_revenue', 'winner_revenue', 'prizes_issued', 'prizes_redeemed', 'revenue_after_prizes'];

        return response()->streamDownload(function () use ($campaign, $columns, $moneyColumns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns, escape: '');
            foreach (array_merge($campaign['days'], [['promo_date' => 'TOTAL'] + $campaign['totals']]) as $row) {
                fputcsv($out, array_map(function ($col) use ($row, $moneyColumns) {
                    $value = $row[$col] ?? '';
                    if (in_array($col, $moneyColumns, true) && is_int($value)) {
                        return Money::toDecimal($value);
                    }

                    return is_bool($value) ? ($value ? 'yes' : 'no') : $value;
                }, $columns), escape: '');
            }
            fclose($out);
        }, 'roll-for-it-revenue-vs-prizes.csv', ['Content-Type' => 'text/csv']);
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
