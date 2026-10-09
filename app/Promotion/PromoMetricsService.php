<?php

namespace App\Promotion;

use App\Models\PromoDaySummary;
use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Shopify\ShopifyAdmin;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Cache;

/**
 * Revenue vs prizes per promo day. `read_orders` only reaches 60 days back,
 * so each day is snapshotted into promo_day_summaries once it closes.
 */
class PromoMetricsService
{
    private const ORDERS_QUERY = <<<'GQL'
        query PromoOrders($query: String!, $after: String) {
          orders(first: 250, after: $after, query: $query, sortKey: CREATED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id
              email
              test
              cancelledAt
              discountCodes
              currentTotalPriceSet { shopMoney { amount } }
              currentSubtotalPriceSet { shopMoney { amount } }
              currentTotalDiscountsSet { shopMoney { amount } }
            }
          }
        }
        GQL;

    public function __construct(private readonly ShopifyAdmin $admin) {}

    /**
     * From the window start to the end of the promo day, because codes stay valid until then.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(Shop $shop, ShopSettings $settings, string $promoDate): array
    {
        $window = new PromoWindow($settings, $shop->timezone);
        $start = $window->windowFor($promoDate)[0] ?? CarbonImmutable::parse($promoDate, $shop->timezone)->startOfDay();
        $end = CarbonImmutable::parse($promoDate, $shop->timezone)->addDay()->startOfDay();

        return [$start, $end];
    }

    /**
     * @return array<string, int|string>
     */
    public function compute(Shop $shop, ShopSettings $settings, string $promoDate): array
    {
        [$start, $end] = $this->period($shop, $settings, $promoDate);

        $rolls = Roll::where('shop_id', $shop->id)
            ->whereDate('promo_date', $promoDate)
            ->where('is_test', false)
            ->get(['email_hash', 'won', 'payout', 'discount_code']);

        $rollerHashes = array_flip($rolls->pluck('email_hash')->all());
        $payoutByCode = [];
        foreach ($rolls->where('won', true) as $roll) {
            if ($roll->discount_code) {
                $payoutByCode[strtoupper($roll->discount_code)] = (int) $roll->payout;
            }
        }

        $totals = [
            'orders_count' => 0, 'revenue' => 0, 'merch_revenue' => 0,
            'roller_orders_count' => 0, 'roller_revenue' => 0,
            'winner_orders_count' => 0, 'winner_revenue' => 0,
            'prizes_redeemed' => 0,
        ];
        $redeemedCodes = [];

        foreach ($this->orders($shop, $start, $end) as $order) {
            if (! empty($order['test']) || ! empty($order['cancelledAt'])) {
                continue;
            }

            $total = Money::toCents($order['currentTotalPriceSet']['shopMoney']['amount'] ?? '0') ?? 0;
            $subtotal = Money::toCents($order['currentSubtotalPriceSet']['shopMoney']['amount'] ?? '0') ?? 0;
            $discounts = Money::toCents($order['currentTotalDiscountsSet']['shopMoney']['amount'] ?? '0') ?? 0;

            $totals['orders_count']++;
            $totals['revenue'] += $total;
            $totals['merch_revenue'] += $subtotal;

            $email = (string) ($order['email'] ?? '');
            if ($email !== '' && isset($rollerHashes[EmailHasher::hash($email)])) {
                $totals['roller_orders_count']++;
                $totals['roller_revenue'] += $total;
            }

            foreach ((array) ($order['discountCodes'] ?? []) as $code) {
                $code = strtoupper((string) $code);
                if (isset($payoutByCode[$code]) && ! isset($redeemedCodes[$code])) {
                    $redeemedCodes[$code] = true;
                    $totals['winner_orders_count']++;
                    $totals['winner_revenue'] += $total;
                    $totals['prizes_redeemed'] += min($payoutByCode[$code], $discounts);

                    break;
                }
            }
        }

        $stats = DayStats::for($shop->id, $promoDate);

        return $totals + [
            'promo_date' => $promoDate,
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'rolls_count' => $stats->rolls,
            'winners_count' => $stats->winners,
            'prizes_issued' => $stats->payout,
        ];
    }

    public function snapshot(Shop $shop, string $promoDate, bool $final = true): PromoDaySummary
    {
        $settings = $shop->settingsOrCreate();
        $data = $this->compute($shop, $settings, $promoDate);

        return PromoDaySummary::updateOrCreate(
            ['shop_id' => $shop->id, 'promo_date' => $promoDate],
            array_merge($data, ['is_final' => $final, 'computed_at' => now()]),
        );
    }

    /**
     * Live figures for the current promo day, cached for five minutes.
     *
     * @return array<string, mixed>|null
     */
    public function today(Shop $shop, bool $fresh = false): ?array
    {
        $settings = $shop->settingsOrCreate();
        $window = new PromoWindow($settings, $shop->timezone);
        if (! $window->isPromoDay()) {
            return null;
        }

        $date = $window->promoDate();
        $key = "metrics-today:{$shop->id}:{$date}";
        if ($fresh) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, 300, fn () => $this->compute($shop, $settings, $date) + ['computed_at' => now()->toIso8601String()]);

        return $this->withDerived($data, $shop);
    }

    /**
     * Promo dates whose day ended at least 30 minutes ago and have no final snapshot.
     *
     * @return list<string>
     */
    public function datesDueForSnapshot(Shop $shop, int $lookbackDays = 7): array
    {
        $settings = $shop->settingsOrCreate();
        $window = new PromoWindow($settings, $shop->timezone);
        $now = CarbonImmutable::now($shop->timezone);

        $final = PromoDaySummary::where('shop_id', $shop->id)->where('is_final', true)
            ->where('promo_date', '>=', $now->subDays($lookbackDays + 1)->toDateString())
            ->pluck('promo_date')->map(fn ($d) => $d->toDateString())->all();

        $rollDates = Roll::where('shop_id', $shop->id)->where('is_test', false)
            ->where('promo_date', '>=', $now->subDays($lookbackDays + 1)->toDateString())
            ->distinct()->pluck('promo_date')->map(fn ($d) => $d->toDateString())->all();

        $due = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $day = $now->subDays($i);
            $date = $day->toDateString();
            $closedAt = $day->addDay()->startOfDay()->addMinutes(30);

            if ($closedAt->isFuture() || in_array($date, $final, true)) {
                continue;
            }
            $hadRolls = in_array($date, $rollDates, true);
            if ($hadRolls || ($settings->enabled && $window->isPromoDay($day))) {
                $due[] = $date;
            }
        }

        return $due;
    }

    /**
     * @return array<string, mixed>
     */
    public function campaign(Shop $shop, ?string $from = null, ?string $to = null): array
    {
        $query = PromoDaySummary::where('shop_id', $shop->id);
        if ($from) {
            $query->where('promo_date', '>=', $from);
        }
        if ($to) {
            $query->where('promo_date', '<=', $to);
        }

        $days = $query->orderByDesc('promo_date')->get();
        $sumKeys = ['orders_count', 'revenue', 'merch_revenue', 'roller_orders_count', 'roller_revenue',
            'winner_orders_count', 'winner_revenue', 'rolls_count', 'winners_count', 'prizes_issued', 'prizes_redeemed'];

        $totals = [];
        foreach ($sumKeys as $key) {
            $totals[$key] = (int) $days->sum($key);
        }

        return [
            'days' => $days->map(fn (PromoDaySummary $s) => $this->withDerived(
                $s->only(array_merge($sumKeys, ['is_final'])) + [
                    'promo_date' => $s->promo_date->toDateString(),
                    'computed_at' => $s->computed_at?->toIso8601String(),
                ],
                $shop,
            ))->all(),
            'totals' => $this->withDerived($totals + ['days_count' => $days->count()], $shop),
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public function withDerived(array $d, Shop $shop): array
    {
        $symbol = Money::symbolFor($shop->currency);
        $revenue = (int) $d['revenue'];
        $redeemed = (int) $d['prizes_redeemed'];
        $otherOrders = (int) $d['orders_count'] - (int) $d['roller_orders_count'];
        $otherRevenue = $revenue - (int) $d['roller_revenue'];

        $derived = [
            'prizes_pct_of_revenue' => $revenue > 0 ? round($redeemed / $revenue * 100, 2) : null,
            'revenue_after_prizes' => $revenue - $redeemed,
            'revenue_per_prize_rand' => $redeemed > 0 ? round($revenue / $redeemed, 2) : null,
            'redemption_rate' => (int) $d['winners_count'] > 0
                ? round((int) $d['winner_orders_count'] / (int) $d['winners_count'] * 100, 1) : null,
            'aov_rollers' => (int) $d['roller_orders_count'] > 0
                ? intdiv((int) $d['roller_revenue'], (int) $d['roller_orders_count']) : null,
            'aov_others' => $otherOrders > 0 ? intdiv($otherRevenue, $otherOrders) : null,
        ];

        $formatted = [];
        foreach (['revenue', 'merch_revenue', 'roller_revenue', 'winner_revenue', 'prizes_issued', 'prizes_redeemed'] as $key) {
            $formatted[$key] = Money::format((int) $d[$key], $symbol);
        }
        $formatted['revenue_after_prizes'] = Money::format($derived['revenue_after_prizes'], $symbol);
        $formatted['aov_rollers'] = $derived['aov_rollers'] !== null ? Money::format($derived['aov_rollers'], $symbol) : null;
        $formatted['aov_others'] = $derived['aov_others'] !== null ? Money::format($derived['aov_others'], $symbol) : null;

        return $d + $derived + ['formatted' => $formatted];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function orders(Shop $shop, CarbonImmutable $start, CarbonImmutable $end): Generator
    {
        $query = sprintf(
            "created_at:>='%s' AND created_at:<'%s'",
            $start->utc()->format('Y-m-d\TH:i:s\Z'),
            $end->utc()->format('Y-m-d\TH:i:s\Z'),
        );

        $after = null;
        do {
            $data = $this->admin->graphql($shop, self::ORDERS_QUERY, ['query' => $query, 'after' => $after]);
            $page = $data['orders'] ?? [];

            foreach ($page['nodes'] ?? [] as $order) {
                yield $order;
            }

            $after = $page['pageInfo']['endCursor'] ?? null;
        } while (($page['pageInfo']['hasNextPage'] ?? false) && $after);
    }
}
