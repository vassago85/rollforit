<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'matched_rolls' => 'integer',
        ];
    }
}
