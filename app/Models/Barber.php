<?php

namespace App\Models;

use Database\Factories\BarberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Barber extends Model implements HasMedia
{
    /** @use HasFactory<BarberFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'specialization_id',
        'schedule',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile();
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
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
}
