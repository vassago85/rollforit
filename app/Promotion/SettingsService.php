<?php

namespace App\Promotion;

use App\Models\Shop;
use App\Models\ShopSettings;
use App\Shopify\Exceptions\ShopifyApiException;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates and saves the merchant's settings, and prepares them for the admin UI.
 */
class SettingsService
{
    private const MONEY_FIELDS = ['min_order', 'max_payout', 'daily_budget'];

    private const TERMS_FIELDS = [
        'promo_days', 'start_time', 'end_time', 'min_order', 'max_payout', 'daily_budget',
        'max_winners_per_day', 'excluded_collections', 'odds_mode', 'odds_n',
        'promoter_name', 'promoter_registration_number', 'promoter_address', 'promoter_email', 'promoter_phone',
    ];

    public function __construct(private readonly TermsService $terms) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['regex:/^\d{1,7}(\.\d{1,2})?$/'];
        $colour = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return [
            'enabled' => ['required', 'boolean'],
            'odds_mode' => ['required', 'in:'.ShopSettings::MODE_GUARANTEED.','.ShopSettings::MODE_CHANCE],
            'odds_n' => ['required', 'integer', 'min:2', 'max:1000'],
            'promo_days' => ['present', 'array'],
            'promo_days.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'min_order' => ['required', ...$money, 'not_regex:/^0+(\.0+)?$/'],
            'max_payout' => ['required', ...$money, 'not_regex:/^0+(\.0+)?$/'],
            'daily_budget' => ['nullable', ...$money, 'not_regex:/^0+(\.0+)?$/'],
            'max_winners_per_day' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'excluded_collections' => ['present', 'array', 'max:50'],
            'excluded_collections.*.id' => ['required', 'string', 'starts_with:gid://shopify/Collection/'],
            'excluded_collections.*.title' => ['nullable', 'string', 'max:255'],
            'die_color' => $colour,
            'die_number_color' => $colour,
            'accent_color' => $colour,
            'roll_duration_ms' => ['required', 'integer', 'min:1000', 'max:8000'],
            'sound_enabled' => ['required', 'boolean'],
            'celebration_enabled' => ['required', 'boolean'],
            'show_countdown' => ['sometimes', 'boolean'],
            'headline' => ['nullable', 'string', 'max:120'],
            'win_message' => ['nullable', 'string', 'max:300'],
            'lose_message' => ['nullable', 'string', 'max:300'],
            'promoter_name' => ['nullable', 'string', 'max:150'],
            'promoter_registration_number' => ['nullable', 'string', 'max:60'],
            'promoter_address' => ['nullable', 'string', 'max:300'],
            'promoter_email' => ['nullable', 'email', 'max:254'],
            'promoter_phone' => ['nullable', 'string', 'max:40'],
            'drawer_selector' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'min_order.required' => 'Set a minimum order value.',
            'max_payout.required' => 'Set a maximum payout.',
            'max_payout.not_regex' => 'The maximum payout must be greater than 0.',
            'min_order.not_regex' => 'The minimum order must be greater than 0.',
            '*.regex' => 'Enter an amount like 500 or 499.99.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{settings: ShopSettings, terms_error: ?string}
     *
     * @throws ValidationException
     */
    public function update(Shop $shop, ShopSettings $settings, array $input): array
    {
        $data = Validator::make($input, $this->rules(), $this->messages())->validate();

        if (! empty($data['start_time']) && ! empty($data['end_time']) && $data['end_time'] <= $data['start_time']) {
            throw ValidationException::withMessages(['end_time' => 'The end time must be after the start time.']);
        }

        foreach (self::MONEY_FIELDS as $field) {
            $data[$field] = Money::toCents($data[$field] ?? null);
        }
        $data['promo_days'] = array_values(array_map('intval', $data['promo_days']));
        sort($data['promo_days']);
        $data['excluded_collections'] = array_values(array_map(
            fn ($c) => ['id' => $c['id'], 'title' => $c['title'] ?? null],
            $data['excluded_collections'],
        ));

        $settings->fill($data);

        if ($settings->enabled && ! $settings->canEnable()) {
            throw ValidationException::withMessages(['enabled' => $settings->enableBlockers()]);
        }

        $termsChanged = $settings->isDirty(self::TERMS_FIELDS);
        $settings->save();

        $termsError = null;
        if ($termsChanged) {
            try {
                $this->terms->syncIfPublished($shop, $settings);
            } catch (ShopifyApiException $e) {
                Log::warning('Terms sync failed after settings save', ['shop' => $shop->domain, 'error' => $e->getMessage()]);
                $termsError = 'Settings saved, but the terms page could not be updated: '.$e->getMessage();
            }
        }

        return ['settings' => $settings->refresh(), 'terms_error' => $termsError];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Shop $shop, ShopSettings $settings): array
    {
        $symbol = Money::symbolFor($shop->currency);
        $window = new PromoWindow($settings, $shop->timezone);

        return [
            'form' => [
                'enabled' => (bool) $settings->enabled,
                'odds_mode' => $settings->odds_mode,
                'odds_n' => (int) $settings->odds_n,
                'promo_days' => array_map('intval', $settings->promo_days ?? []),
                'start_time' => $settings->start_time,
                'end_time' => $settings->end_time,
                'min_order' => $settings->min_order !== null ? Money::toDecimal($settings->min_order) : null,
                'max_payout' => $settings->max_payout !== null ? Money::toDecimal($settings->max_payout) : null,
                'daily_budget' => $settings->daily_budget !== null ? Money::toDecimal($settings->daily_budget) : null,
                'max_winners_per_day' => $settings->max_winners_per_day,
                'excluded_collections' => $settings->excluded_collections ?? [],
                'die_color' => $settings->die_color,
                'die_number_color' => $settings->die_number_color,
                'accent_color' => $settings->accent_color,
                'roll_duration_ms' => (int) $settings->roll_duration_ms,
                'sound_enabled' => (bool) $settings->sound_enabled,
                'celebration_enabled' => (bool) $settings->celebration_enabled,
                'show_countdown' => (bool) $settings->show_countdown,
                'headline' => $settings->headline,
                'win_message' => $settings->win_message,
                'lose_message' => $settings->lose_message,
                'promoter_name' => $settings->promoter_name,
                'promoter_registration_number' => $settings->promoter_registration_number,
                'promoter_address' => $settings->promoter_address,
                'promoter_email' => $settings->promoter_email,
                'promoter_phone' => $settings->promoter_phone,
                'drawer_selector' => $settings->drawer_selector,
            ],
            'shop' => [
                'domain' => $shop->domain,
                'name' => $shop->name,
                'currency' => $shop->currency,
                'symbol' => $symbol,
                'timezone' => $shop->timezone,
                'storefront_url' => $shop->storefrontUrl(),
            ],
            'status' => [
                'can_enable' => $settings->canEnable(),
                'enable_blockers' => $settings->enableBlockers(),
                'open_now' => $settings->enabled && $settings->canEnable() && $window->isOpen(),
                'terms_url' => $settings->terms_url,
                'terms_published' => $settings->hasPublishedTerms(),
                'terms_reviewed' => (bool) $settings->terms_reviewed,
                'preview_expires_at' => $settings->preview_expires_at?->isFuture()
                    ? $settings->preview_expires_at->toIso8601String() : null,
                'preview_outcome' => $settings->preview_expires_at?->isFuture() ? $settings->preview_outcome : null,
            ],
            'warnings' => self::warnings($settings, $symbol),
            'cost_per_roll' => self::costPerRoll($settings),
            'cost_per_roll_formatted' => self::costPerRoll($settings) !== null
                ? Money::format(self::costPerRoll($settings), $symbol) : null,
            'defaults' => [
                'headline' => WidgetCopy::DEFAULT_HEADLINE,
                'win_message' => WidgetCopy::DEFAULT_WIN,
                'lose_message' => WidgetCopy::DEFAULT_LOSE,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function warnings(ShopSettings $settings, string $symbol = 'R'): array
    {
        $warnings = [];

        if ($settings->min_order && $settings->max_payout && $settings->max_payout < $settings->min_order) {
            $warnings[] = sprintf(
                'Your maximum payout (%s) is less than your minimum order (%s). Winners will always pay something; that is allowed, just make sure it is what you want.',
                Money::format($settings->max_payout, $symbol),
                Money::format($settings->min_order, $symbol),
            );
        }
        if ($settings->daily_budget && $settings->max_payout && $settings->daily_budget < $settings->max_payout) {
            $warnings[] = 'Your daily budget is less than one maximum payout, so the first win of the day may be reduced.';
        }

        return $warnings;
    }

    /**
     * Worst-case expected prize cost per roll: max_payout / N, in cents.
     */
    public static function costPerRoll(ShopSettings $settings): ?int
    {
        if (! $settings->max_payout || ! $settings->odds_n) {
            return null;
        }

        return intdiv((int) $settings->max_payout + intdiv((int) $settings->odds_n, 2), (int) $settings->odds_n);
    }
}
