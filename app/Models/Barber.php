<?php

namespace App\Models;

use Database\Factories\BarberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Barber extends Model implements HasMedia
{
    /** @use HasFactory<BarberFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'name',
        'specialization_id',
        'price',
        'salary_percent',
        'schedule',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'salary_percent' => 'integer',
            'schedule' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot('price');
    }

    public function priceForService(?int $serviceId): ?int
    {
        if (! $serviceId) {
            return $this->price;
        }

        /** @var Service|null $pivot */
        $pivot = $this->services->firstWhere('id', $serviceId);

        return $pivot?->pivot->price ?? $this->price;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public string $photoUrl {
        get => $this->getFirstMediaUrl('photo') ?: '';
    }

    /**
     * Цена в формате "12 000 сум" (PHP 8.4 property hook).
     */
    public string $formattedPrice {
        get => number_format((int) $this->price, 0, '.', ' ').' сум';
    }
}
