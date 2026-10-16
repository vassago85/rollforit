<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Roll extends Model
{
    use HasFactory;

    public const CODE_NONE = 'none';

    public const CODE_PENDING = 'pending';

    public const CODE_CREATED = 'created';

    public const CODE_FAILED = 'failed';

    public const CODE_REFUSED = 'refused';

    public const CHANNEL_ONLINE = 'online';

    public const CHANNEL_POS = 'pos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'promo_date' => 'date:Y-m-d',
            'cart_subtotal' => 'integer',
            'eligible_subtotal' => 'integer',
            'eligible_product_ids' => 'array',
            'picked_number' => 'integer',
            'displayed_number' => 'integer',
            'won' => 'boolean',
            'payout' => 'integer',
            'discount_ends_at' => 'datetime',
            'is_test' => 'boolean',
            'position' => 'integer',
            'fulfilled_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'redeemed_amount' => 'integer',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class);
    }
}
