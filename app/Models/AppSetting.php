<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * App-wide key/value settings the operator edits from the UI. Secrets are
 * encrypted at rest with the app key, so the app_settings table is safe to
 * include in a database backup.
 *
 * Reads are cached for 60 seconds per key, so the per-request `boot()` hook
 * that applies these to `config()` doesn't hit the DB on every single request.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'encrypted'];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::cacheKey($key), 60, function () use ($key, $default) {
            $row = self::query()->where('key', $key)->first();
            if (! $row) {
                return $default;
            }

            if (! $row->encrypted) {
                return $row->value;
            }

            try {
                return Crypt::decryptString((string) $row->value);
            } catch (Throwable) {
                return $default;
            }
        });
    }

    public static function put(string $key, ?string $value, bool $encrypted = false): void
    {
        $stored = $value;
        if ($encrypted && $value !== null && $value !== '') {
            $stored = Crypt::encryptString($value);
        }

        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'encrypted' => $encrypted],
        );

        Cache::forget(self::cacheKey($key));
    }

    public static function forget(string $key): void
    {
        self::query()->where('key', $key)->delete();
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return "app_setting:{$key}";
    }
}
