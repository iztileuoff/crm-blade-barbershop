<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    private const CACHE_KEY = 'settings.all';

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::cachedValues()->get($key) ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return Collection<string, string|null>
     */
    private static function cachedValues(): Collection
    {
        $values = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        return collect($values);
    }
}
