<?php

namespace App\Promotion;

use App\Models\Shop;
use App\Models\ShopSettings;
use App\Models\TermsVersion;
use App\Shopify\Exceptions\ShopifyApiException;
use App\Shopify\ShopifyAdmin;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * Generates, publishes and versions the promotion's terms page.
 */
class TermsService
{
    public const PAGE_TITLE = 'Roll4It: Terms and Conditions';

    public function __construct(private readonly ShopifyAdmin $admin) {}

    /**
     * Fields that must be filled before generated terms can be published.
     *
     * @return list<string>
     */
    public function missingFields(ShopSettings $settings): array
    {
        $required = [
            'promoter_name' => 'Promoter name',
            'promoter_address' => 'Physical address',
            'promoter_email' => 'Contact email',
            'min_order' => 'Minimum order value',
            'max_payout' => 'Maximum payout',
            'start_time' => 'Start time',
            'end_time' => 'End time',
        ];

        $missing = [];
        foreach ($required as $field => $label) {
            if (blank($settings->{$field})) {
                $missing[] = $label;
            }
        }
        if (empty($settings->promo_days)) {
            $missing[] = 'Promo days';
        }

        return $missing;
    }

    public function render(Shop $shop, ShopSettings $settings): string
    {
        $symbol = Money::symbolFor($shop->currency);
        $days = PromoWindow::dayNames($settings->promo_days ?? []);

        return View::make('terms.generated', [
            'shop' => $shop,
            'settings' => $settings,
            'days' => $days,
            'daysText' => self::joinList($days),
            'minOrder' => Money::format($settings->min_order, $symbol),
            'maxPayout' => Money::format($settings->max_payout, $symbol),
            'maxPayoutPercent' => $settings->max_payout_percent !== null ? (int) $settings->max_payout_percent : null,
            'dailyBudget' => $settings->daily_budget ? Money::format($settings->daily_budget, $symbol) : null,
            'oddsStatement' => $settings->isGuaranteed()
                ? "One in every {$settings->odds_n} entries wins. Entries are grouped in blocks of {$settings->odds_n}, and exactly one entry in each block wins. Blocks carry over from one promo day to the next until they are complete."
                : "Each roll has a 1 in {$settings->odds_n} chance to win, decided independently for every roll.",
            'excludedTitles' => array_values(array_filter(array_map(
                fn ($c) => is_array($c) ? ($c['title'] ?? null) : null,
                $settings->excluded_collections ?? [],
            ))),
            'ipDays' => (int) config('shopify.retention.ip_days'),
            'retentionYears' => (int) config('shopify.retention.roll_years'),
            'nextVersion' => $this->latestVersion($shop) + 1,
            'date' => now($shop->timezone)->toFormattedDateString(),
        ])->render();
    }

    /**
     * Publishes (creates or updates) the terms page and records a new version
     * when the content changed.
     *
     * @throws ShopifyApiException
     */
    public function publish(Shop $shop, ShopSettings $settings): ShopSettings
    {
        return $settings->terms_mode === ShopSettings::TERMS_OWN
            ? $this->publishOwnPage($shop, $settings)
            : $this->publishGenerated($shop, $settings);
    }

    /**
     * Keeps the published page in sync after settings change. No-op until the
     * merchant has published terms at least once.
     */
    public function syncIfPublished(Shop $shop, ShopSettings $settings): ?ShopSettings
    {
        if (! $settings->hasPublishedTerms()) {
            return null;
        }

        return $this->publish($shop, $settings);
    }

    public function latestVersion(Shop $shop): int
    {
        return (int) TermsVersion::where('shop_id', $shop->id)->max('version');
    }

    /**
     * @return list<array{id: string, title: string, handle: string, isPublished: bool}>
     */
    public function listPages(Shop $shop): array
    {
        $data = $this->admin->graphql($shop, <<<'GQL'
            query Pages {
              pages(first: 100, sortKey: TITLE) {
                nodes { id title handle isPublished }
              }
            }
            GQL);

        return $data['pages']['nodes'] ?? [];
    }

    private function publishGenerated(Shop $shop, ShopSettings $settings): ShopSettings
    {
        $missing = $this->missingFields($settings);
        if ($missing !== []) {
            throw new ShopifyApiException('Fill these fields before publishing terms: '.implode(', ', $missing).'.');
        }

        $body = $this->render($shop, $settings);
        $hash = hash('sha256', $this->stripVersionFooter($body));
        $latest = TermsVersion::where('shop_id', $shop->id)->orderByDesc('version')->first();

        if ($settings->generated_page_id && $latest && $latest->content_hash === $hash && $settings->hasPublishedTerms()) {
            return $settings;
        }

        $page = $settings->generated_page_id
            ? $this->updatePage($shop, $settings->generated_page_id, $body)
            : $this->createPage($shop, $body);

        return DB::transaction(function () use ($shop, $settings, $page, $body, $hash) {
            $this->recordVersion($shop, ShopSettings::TERMS_GENERATED, $body, $hash);

            $settings->forceFill([
                'generated_page_id' => $page['id'],
                'terms_url' => $shop->storefrontUrl().'/pages/'.$page['handle'],
                'terms_published_at' => now(),
            ])->save();

            return $settings;
        });
    }

    private function publishOwnPage(Shop $shop, ShopSettings $settings): ShopSettings
    {
        if (! $settings->own_page_id) {
            throw new ShopifyApiException('Choose your terms page first.');
        }

        $data = $this->admin->graphql($shop, <<<'GQL'
            query OwnTermsPage($id: ID!) {
              page(id: $id) { id title handle isPublished body }
            }
            GQL, ['id' => $settings->own_page_id]);

        $page = $data['page'] ?? null;
        if (! $page) {
            throw new ShopifyApiException('That page no longer exists.');
        }
        if (! $page['isPublished']) {
            throw new ShopifyApiException('Your terms page is not published. Publish it in Online Store > Pages first.');
        }

        $body = (string) ($page['body'] ?? '');
        $hash = hash('sha256', $body);

        return DB::transaction(function () use ($shop, $settings, $page, $body, $hash) {
            $latest = TermsVersion::where('shop_id', $shop->id)->orderByDesc('version')->first();
            if (! $latest || $latest->content_hash !== $hash || $latest->source !== ShopSettings::TERMS_OWN) {
                $this->recordVersion($shop, ShopSettings::TERMS_OWN, $body, $hash);
            }

            $settings->forceFill([
                'terms_url' => $shop->storefrontUrl().'/pages/'.$page['handle'],
                'terms_published_at' => now(),
            ])->save();

            return $settings;
        });
    }

    /**
     * @return array{id: string, handle: string}
     */
    private function createPage(Shop $shop, string $body): array
    {
        $data = $this->admin->graphql($shop, <<<'GQL'
            mutation CreateTermsPage($page: PageCreateInput!) {
              pageCreate(page: $page) {
                page { id handle isPublished }
                userErrors { code field message }
              }
            }
            GQL, ['page' => [
            'title' => self::PAGE_TITLE,
            'handle' => config('shopify.terms_handle'),
            'body' => $body,
            'isPublished' => true,
        ]]);

        $payload = $data['pageCreate'] ?? [];
        ShopifyAdmin::assertNoUserErrors($payload, 'Creating the terms page failed');

        return $payload['page'];
    }

    /**
     * @return array{id: string, handle: string}
     */
    private function updatePage(Shop $shop, string $pageId, string $body): array
    {
        $data = $this->admin->graphql($shop, <<<'GQL'
            mutation UpdateTermsPage($id: ID!, $page: PageUpdateInput!) {
              pageUpdate(id: $id, page: $page) {
                page { id handle isPublished }
                userErrors { code field message }
              }
            }
            GQL, ['id' => $pageId, 'page' => [
            'title' => self::PAGE_TITLE,
            'body' => $body,
            'isPublished' => true,
        ]]);

        $payload = $data['pageUpdate'] ?? [];
        ShopifyAdmin::assertNoUserErrors($payload, 'Updating the terms page failed');

        return $payload['page'];
    }

    private function recordVersion(Shop $shop, string $source, string $body, string $hash): TermsVersion
    {
        return TermsVersion::create([
            'shop_id' => $shop->id,
            'version' => $this->latestVersion($shop) + 1,
            'source' => $source,
            'content' => $body,
            'content_hash' => $hash,
            'published_at' => now(),
        ]);
    }

    private function stripVersionFooter(string $body): string
    {
        return (string) preg_replace('#<p class="rfi-terms-version">.*?</p>#s', '', $body);
    }

    /**
     * @param  list<string>  $items
     */
    public static function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
