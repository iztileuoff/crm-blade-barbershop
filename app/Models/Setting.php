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
     * Хендл Telegram, приведённый к голому виду: без схемы, домена и «@».
     *
     * Поле в настройках свободное, и туда с равным успехом вставляют
     * «@blade_barbershop», «blade_barbershop» и целиком «https://t.me/…».
     * Нормализация одна на всех, чтобы из последнего не собиралось
     * «https://t.me/https://t.me/blade_barbershop».
     */
    public static function normalizeTelegramHandle(?string $value): ?string
    {
        $handle = trim((string) $value);
        $handle = preg_replace('~^\s*(?:https?://)?(?:www\.)?(?:t(?:elegram)?\.me|telegram\.dog)/~i', '', $handle) ?? $handle;
        $handle = ltrim(trim($handle), '@');

        return $handle !== '' ? $handle : null;
    }

    /**
     * Ссылка на бота салона — единственная сборка на приложение: раньше её
     * независимо считали и макет публичной брони, и сам компонент записи.
     */
    public static function telegramUrl(): ?string
    {
        $handle = static::normalizeTelegramHandle(static::get('telegram'));

        return $handle !== null ? 'https://t.me/'.$handle : null;
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
