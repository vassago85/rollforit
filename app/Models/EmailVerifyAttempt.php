<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for a single storefront email-verify event. See the
 * migration for the full rationale. Reporting reads these; nothing in the roll
 * pipeline relies on them.
 */
class EmailVerifyAttempt extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_SEND = 'send';

    public const ACTION_VERIFY = 'verify';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'attempt_number' => 'integer',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
