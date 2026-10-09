<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'winning_position' => 'integer',
            'next_position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(Roll::class);
    }
}
