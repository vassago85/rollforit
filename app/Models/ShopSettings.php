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

    public const SCHEDULE_WEEKLY = 'weekly';

    public const SCHEDULE_DATES = 'dates';

    protected $table = 'shop_settings';

    protected $guarded = ['id', 'shop_id'];

    protected $attributes = [
        'promo_days' => '[]',
        'promo_dates' => '[]',
        'schedule_mode' => self::SCHEDULE_WEEKLY,
        'excluded_collections' => '[]',
        'odds_mode' => self::MODE_GUARANTEED,
        'odds_n' => 10,
        'dice_count' => 1,
        'terms_mode' => self::TERMS_GENERATED,
    ];

    public function pickRange(): array
    {
        return (int) $this->dice_count === 2 ? [2, 12] : [1, 10];
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'odds_n' => 'integer',
            'dice_count' => 'integer',
            'promo_days' => 'array',
            'promo_dates' => 'array',
            'min_order' => 'integer',
            'max_payout' => 'integer',
            'max_payout_percent' => 'integer',
            'daily_budget' => 'integer',
            'max_winners_per_day' => 'integer',
            'excluded_collections' => 'array',
            'roll_duration_ms' => 'integer',
            'sound_enabled' => 'boolean',
            'celebration_enabled' => 'boolean',
            'show_countdown' => 'boolean',
            'pos_enabled' => 'boolean',
            'pos_secret' => 'encrypted',
            'pos_secret_rotated_at' => 'datetime',
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
        // At least one daily ceiling must be set. Without either a cash budget or a
        // winners-per-day cap, guaranteed mode can book several max_payout winners
        // in a single day whenever a block completes and reopens — the per-roll
        // max is the only brake, so a bad run uncaps your spend. See docs on odds.
        if ((! $this->daily_budget || $this->daily_budget <= 0)
            && (! $this->max_winners_per_day || $this->max_winners_per_day <= 0)) {
            $blockers[] = 'Set a daily budget or a maximum number of winners per day. Without one of these, a bad run could rack up unlimited payouts in a single day.';
        }
        if (! $this->hasPublishedTerms()) {
            $blockers[] = 'Publish a terms page.';
        }
        if (! $this->start_time || ! $this->end_time) {
            $blockers[] = 'Choose promo days and times.';
        } elseif ($this->isWeeklySchedule() && empty($this->promo_days)) {
            $blockers[] = 'Choose promo days and times.';
        } elseif ($this->isDatesSchedule() && empty($this->promo_dates)) {
            // Dates mode with no dates means "campaign exists but has no runs scheduled" —
            // same shape of problem as weekly with no days ticked.
            $blockers[] = 'Add at least one promo date.';
        }

        return $blockers;
    }

    public function canEnable(): bool
    {
        return $this->enableBlockers() === [];
    }

    public function isWeeklySchedule(): bool
    {
        return $this->schedule_mode !== self::SCHEDULE_DATES;
    }

    public function isDatesSchedule(): bool
    {
        return $this->schedule_mode === self::SCHEDULE_DATES;
    }
}
