<?php

namespace App\Promotion;

use App\Models\Shop;
use App\Models\ShopSettings;
use App\Support\Money;

/**
 * Ready-to-paste advert blocks generated from the current settings.
 */
class AdvertCopyService
{
    public const ONE_ROLL = 'One roll per customer per day.';

    /**
     * Templates from the spec. `{odds}` is the mode-dependent odds sentence;
     * `{day}s` is replaced before `{day}` so multi-day promos read naturally.
     */
    public const TEMPLATES = [
        'short_social' => [
            'label' => 'Short social post',
            'text' => 'Roll4It this {day}. Spend {min_order} or more at {store_name}, roll {dice_phrase} at checkout, and win {prize} off your order. {odds} One roll per customer per day. T&Cs: {terms_url}',
        ],
        'long_social' => [
            'label' => 'Long social post',
            'text' => "Every {day}, your order could be on us.\n\nSpend {min_order} or more at {store_name} and you get one roll of {dice_phrase} before you pay. Pick your number and roll. {odds_clause}, and a win takes {prize} off your order.\n\nOne roll per customer per day. Valid {day}s from {start_time} to {end_time}. Shipping and gift cards excluded.\n\nFull terms: {terms_url}",
        ],
        'email' => [
            'label' => 'Email subject and body',
            'text' => "Subject: Roll {dice_phrase} this {day}, win {prize} off\n\nThis {day}, every order of {min_order} or more at {store_name} gets one roll of {dice_phrase} before payment. {odds_clause}, and a win takes {prize} off your order.\n\nHow it works: fill your cart, pick {pick_phrase} on the cart page and roll. If you win, the discount is applied straight away.\n\nOne roll per customer per day. Runs {day}s from {start_time} to {end_time}. Shipping and gift cards excluded.\n\nShop now: {store_url}\nFull terms: {terms_url}",
        ],
        'whatsapp' => [
            'label' => 'WhatsApp / SMS',
            'text' => '{store_name}: Roll4It this {day}. Spend {min_order}+, roll {dice_phrase}, win {prize} off. {odds_short} One roll per customer per day. T&Cs {terms_url}',
        ],
        'banner' => [
            'label' => 'Website banner',
            'text' => '{day}s only: spend {min_order}, roll {dice_phrase}, win {prize} off. T&Cs apply.',
        ],
        'print' => [
            'label' => 'Print / in-store',
            'text' => 'Roll4It every {day} at {store_url}. Spend {min_order} or more, roll {dice_phrase}, win {prize} off your order. {odds} One roll per customer per day. Full terms at {terms_url}',
        ],
    ];

    /**
     * @return array{values: array<string, string>, missing: list<string>, blocks: list<array{key: string, label: string, text: ?string, error: ?string}>}
     */
    public function generate(Shop $shop, ShopSettings $settings): array
    {
        $values = $this->values($shop, $settings);
        $missing = $this->missingValues($values);

        $blocks = [];
        foreach (self::TEMPLATES as $key => $template) {
            if ($missing !== []) {
                $blocks[] = ['key' => $key, 'label' => $template['label'], 'text' => null,
                    'error' => 'Fill these settings first: '.implode(', ', $missing).'.'];

                continue;
            }

            $text = $this->complete($this->fill($template['text'], $values), $values);
            $problems = array_merge($this->missingRequirements($text, $values), $this->freeViolations($text, $values));

            $blocks[] = ['key' => $key, 'label' => $template['label'],
                'text' => $problems === [] ? $text : null,
                'error' => $problems === [] ? null : implode(' ', $problems)];
        }

        return ['values' => $values, 'missing' => $missing, 'blocks' => $blocks];
    }

    /**
     * Values adverts depend on, for the "settings changed since you copied" warning.
     *
     * @return array<string, string>
     */
    public function snapshot(Shop $shop, ShopSettings $settings): array
    {
        $v = $this->values($shop, $settings);

        return [
            'Promo days' => $v['day'],
            'Start time' => $v['start_time'],
            'End time' => $v['end_time'],
            'Minimum order' => $v['min_order'],
            'Maximum payout' => $v['max_payout'],
            'Prize' => $v['prize'],
            'Odds' => $v['odds'],
            'Dice' => $v['dice_phrase'].' (pick '.$v['pick_phrase'].')',
            'Terms URL' => $v['terms_url'],
            'Store name' => $v['store_name'],
        ];
    }

    /**
     * @return list<array{field: string, from: string, to: string}>
     */
    public function changesSinceAck(Shop $shop, ShopSettings $settings): array
    {
        $ack = $settings->advert_ack_snapshot;
        if (! is_array($ack) || $ack === []) {
            return [];
        }

        $changes = [];
        foreach ($this->snapshot($shop, $settings) as $field => $now) {
            $was = (string) ($ack[$field] ?? '');
            if ($was !== $now) {
                $changes[] = ['field' => $field, 'from' => $was === '' ? '(not set)' : $was, 'to' => $now === '' ? '(not set)' : $now];
            }
        }

        return $changes;
    }

    public function acknowledge(Shop $shop, ShopSettings $settings): void
    {
        $settings->forceFill(['advert_ack_snapshot' => $this->snapshot($shop, $settings)])->save();
    }

    /**
     * @return array<string, string>
     */
    public function values(Shop $shop, ShopSettings $settings): array
    {
        $symbol = Money::symbolFor($shop->currency);
        // Weekly: {day} = "Friday", {days} = "Fridays"; dates: both read as the
        // literal calendar list ("30 November 2026, 24 December 2026") so a
        // campaign advert never says "on Mondays" when the campaign is really a
        // one-off date — merchants have been caught by that before.
        $days = $settings->isDatesSchedule()
            ? []
            : PromoWindow::dayNames($settings->promo_days ?? []);
        $dateList = TermsService::formatPromoDates($settings->promo_dates ?? [], $shop->timezone);
        $dateJoined = TermsService::joinList($dateList);
        $n = (int) $settings->odds_n;
        $diceCount = (int) $settings->dice_count;
        [$pickMin, $pickMax] = $settings->pickRange();

        $guaranteed = $settings->isGuaranteed();
        $maxPayout = $settings->max_payout ? Money::format($settings->max_payout, $symbol) : '';
        $percent = $settings->max_payout_percent !== null ? (int) $settings->max_payout_percent : null;
        $prize = $maxPayout === ''
            ? ''
            : ($percent
                ? sprintf('up to %d%% of your cart (max %s)', $percent, $maxPayout)
                : 'up to '.$maxPayout);

        return [
            'day' => $settings->isDatesSchedule() ? $dateJoined : TermsService::joinList($days),
            'days' => $settings->isDatesSchedule() ? $dateJoined : TermsService::joinList(array_map(fn ($d) => $d.'s', $days)),
            'start_time' => (string) $settings->start_time,
            'end_time' => (string) $settings->end_time,
            'min_order' => $settings->min_order ? Money::format($settings->min_order, $symbol) : '',
            'max_payout' => $maxPayout,
            'prize' => $prize,
            'store_name' => (string) ($shop->name ?? ''),
            'store_url' => $shop->storefrontUrl(),
            'terms_url' => (string) ($settings->hasPublishedTerms() ? $settings->terms_url : ''),
            'odds' => $guaranteed ? "One in every {$n} rolls wins." : "Each roll has a 1 in {$n} chance to win.",
            'odds_clause' => $guaranteed ? "One in every {$n} rolls wins" : "Each roll has a 1 in {$n} chance to win",
            'odds_short' => $guaranteed ? "1 in {$n} rolls wins." : "Each roll has a 1 in {$n} chance to win.",
            // Dice-aware phrasing so switching to two dice rewrites every advert on its own.
            'dice_phrase' => $diceCount === 2 ? 'the dice' : 'the die',
            'pick_phrase' => $diceCount === 2
                ? "a sum from {$pickMin} to {$pickMax}"
                : "a number from {$pickMin} to {$pickMax}",
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function missingValues(array $values): array
    {
        $labels = [
            'day' => 'Promo days',
            'start_time' => 'Start time',
            'end_time' => 'End time',
            'min_order' => 'Minimum order value',
            'max_payout' => 'Maximum payout',
            'store_name' => 'Store name',
            'store_url' => 'Store URL',
            'terms_url' => 'Published terms page',
        ];

        return array_values(array_map(
            fn ($key) => $labels[$key],
            array_filter(array_keys($labels), fn ($key) => trim($values[$key]) === ''),
        ));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $template, array $values): string
    {
        $text = str_replace('{day}s', $values['days'], $template);

        foreach ($values as $key => $value) {
            $text = str_replace('{'.$key.'}', $value, $text);
        }

        return $text;
    }

    /**
     * Appends any required element a short template leaves out.
     *
     * @param  array<string, string>  $values
     */
    private function complete(string $text, array $values): string
    {
        $tail = [];
        if (! $this->containsOdds($text, $values)) {
            $tail[] = $values['odds'];
        }
        if (stripos($text, rtrim(self::ONE_ROLL, '.')) === false) {
            $tail[] = self::ONE_ROLL;
        }
        if (! str_contains($text, $values['terms_url'])) {
            $tail[] = 'Full terms: '.$values['terms_url'];
        }

        return $tail === [] ? $text : rtrim($text).' '.implode(' ', $tail);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function missingRequirements(string $text, array $values): array
    {
        $problems = [];
        $firstDay = explode(' and ', explode(', ', $values['day'])[0])[0];

        if ($firstDay === '' || ! str_contains($text, $firstDay)) {
            $problems[] = 'Missing promo day.';
        }
        if (! str_contains($text, $values['min_order'])) {
            $problems[] = 'Missing minimum order.';
        }
        if (! str_contains($text, $values['max_payout'])) {
            $problems[] = 'Missing maximum payout.';
        }
        if (! $this->containsOdds($text, $values)) {
            $problems[] = 'Missing odds statement.';
        }
        if (stripos($text, rtrim(self::ONE_ROLL, '.')) === false) {
            $problems[] = 'Missing "one roll per customer per day".';
        }
        if (! str_contains($text, $values['terms_url'])) {
            $problems[] = 'Missing terms URL.';
        }

        return $problems;
    }

    /**
     * Any sentence mentioning "free" must also say "up to {max_payout}".
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function freeViolations(string $text, array $values): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $text) ?: [];
        foreach ($sentences as $sentence) {
            if (preg_match('/\bfree\b/i', $sentence) && stripos($sentence, 'up to '.$values['max_payout']) === false) {
                return ['A sentence says "free" without "up to '.$values['max_payout'].'".'];
            }
        }

        return [];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function containsOdds(string $text, array $values): bool
    {
        return stripos($text, rtrim($values['odds'], '.')) !== false
            || stripos($text, rtrim($values['odds_short'], '.')) !== false;
    }
}
