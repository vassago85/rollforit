<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    public function settings(): HasOne
    {
        return $this->hasOne(ShopSettings::class);
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(Roll::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    public function termsVersions(): HasMany
    {
        return $this->hasMany(TermsVersion::class);
    }

    public function isInstalled(): bool
    {
        return $this->access_token !== null && $this->uninstalled_at === null;
    }

    public function accessTokenNeedsRefresh(): bool
    {
        if ($this->access_token_expires_at === null) {
            return false;
        }

        return $this->access_token_expires_at->subSeconds(config('shopify.token_refresh_skew'))->isPast();
    }

    public function settingsOrCreate(): ShopSettings
    {
        return $this->settings()->firstOrCreate([]);
    }

    public function storefrontUrl(): string
    {
        return rtrim($this->primary_domain_url ?: 'https://'.$this->domain, '/');
    }
}
