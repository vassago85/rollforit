<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSettings extends Model
{
    use HasFactory;

    public const MODE_GUARANTEED = 'guaranteed';

    public const MODE_CHANCE = 'chance';

    public const TERMS_GENERATED = 'generated';

    public const TERMS_OWN = 'own';

    protected $table = 'shop_settings';

    protected $guarded = ['id', 'shop_id'];

    protected $attributes = [
        'promo_days' => '[]',
        'excluded_collections' => '[]',
        'odds_mode' => self::MODE_GUARANTEED,
        'odds_n' => 10,
        'terms_mode' => self::TERMS_GENERATED,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'odds_n' => 'integer',
            'promo_days' => 'array',
            'min_order' => 'integer',
            'max_payout' => 'integer',
            'daily_budget' => 'integer',
            'max_winners_per_day' => 'integer',
            'excluded_collections' => 'array',
            'roll_duration_ms' => 'integer',
            'sound_enabled' => 'boolean',
            'celebration_enabled' => 'boolean',
            'show_countdown' => 'boolean',
            'terms_reviewed' => 'boolean',
            'terms_published_at' => 'datetime',
            'advert_ack_snapshot' => 'array',
            'preview_expires_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isGuaranteed(): bool
    {
        return $this->odds_mode === self::MODE_GUARANTEED;
    }

    /**
     * @return list<string>
     */
    public function excludedCollectionIds(): array
    {
        return array_values(array_filter(array_map(
            fn ($c) => is_array($c) ? ($c['id'] ?? null) : $c,
            $this->excluded_collections ?? [],
        )));
    }

    public function hasPublishedTerms(): bool
    {
        return $this->terms_url !== null && $this->terms_published_at !== null;
    }

    /**
     * Reasons the promotion cannot be switched on yet. Empty when it can.
     *
     * @return list<string>
     */
    public function enableBlockers(): array
    {
        $blockers = [];

        if (! $this->min_order || $this->min_order <= 0) {
            $blockers[] = 'Set a minimum order value.';
        }
        if (! $this->max_payout || $this->max_payout <= 0) {
            $blockers[] = 'Set a maximum payout greater than 0.';
        }
        if (! $this->hasPublishedTerms()) {
            $blockers[] = 'Publish a terms page.';
        }
        if (empty($this->promo_days) || ! $this->start_time || ! $this->end_time) {
            $blockers[] = 'Choose promo days and times.';
        }

        return $blockers;
    }

    public function canEnable(): bool
    {
        return $this->enableBlockers() === [];
    }
}
