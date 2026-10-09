<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TermsVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
