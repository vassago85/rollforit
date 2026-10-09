<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoDaySummary extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'promo_date' => 'date:Y-m-d',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'orders_count' => 'integer',
            'revenue' => 'integer',
            'merch_revenue' => 'integer',
            'roller_orders_count' => 'integer',
            'roller_revenue' => 'integer',
            'winner_orders_count' => 'integer',
            'winner_revenue' => 'integer',
            'rolls_count' => 'integer',
            'winners_count' => 'integer',
            'prizes_issued' => 'integer',
            'prizes_redeemed' => 'integer',
            'is_final' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }
}
