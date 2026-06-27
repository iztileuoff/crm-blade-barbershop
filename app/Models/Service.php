<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * Locales a service name is translated into. The first one is the fallback.
     *
     * @var list<string>
     */
    public const LOCALES = ['ru', 'uz', 'kaa'];

    protected $fillable = [
        'name',
        'duration_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The service name for the active app locale, falling back to Russian.
     *
     * The column stores a JSON map of translations; this accessor keeps every
     * existing `$service->name` call site working without changes.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): string {
                $translations = array_filter(
                    self::decodeTranslations($value),
                    static fn ($name): bool => is_string($name) && $name !== '',
                );
                $locale = app()->getLocale();

                return $translations[$locale]
                    ?? $translations['ru']
                    ?? (string) (reset($translations) ?: '');
            },
        );
    }

    /**
     * All translations of the name keyed by locale, used by the admin form.
     *
     * @return Attribute<array<string, string>, never>
     */
    protected function translations(): Attribute
    {
        return Attribute::make(
            get: fn (): array => self::decodeTranslations($this->attributes['name'] ?? null),
        );
    }

    /**
     * Build the JSON payload stored in the `name` column from per-locale values.
     *
     * @param  array<string, string>  $translations
     */
    public static function encodeTranslations(array $translations): string
    {
        $payload = [];
        foreach (self::LOCALES as $locale) {
            $payload[$locale] = trim((string) ($translations[$locale] ?? ''));
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode the stored value into a locale => name map. A legacy plain string
     * (pre-translation rows) is mirrored across every locale as a fallback.
     *
     * @return array<string, string>
     */
    public static function decodeTranslations(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return array_fill_keys(self::LOCALES, $value);
    }

    public function barbers(): BelongsToMany
    {
        return $this->belongsToMany(Barber::class)->withPivot('price');
    }

    public function appointments(): BelongsToMany
    {
        return $this->belongsToMany(Appointment::class)->withPivot('amount');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
