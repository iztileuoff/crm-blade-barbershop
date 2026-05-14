<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'barber_id',
        'starts_at',
        'ends_at',
        'status',
        'price',
        'note',
        'notified_30min',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'price' => 'integer',
            'notified_30min' => 'boolean',
        ];
    }

    public string $formattedPrice {
        get => number_format((int) ($this->price ?? 0), 0, '.', ' ').' сум';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot('amount');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            AppointmentStatus::Cancelled->value,
            AppointmentStatus::Completed->value,
        ]);
    }

    public function scopeForDay(Builder $query, \DateTimeInterface $day): Builder
    {
        return $query->whereBetween('starts_at', [
            Carbon::instance($day)->startOfDay(),
            Carbon::instance($day)->endOfDay(),
        ]);
    }
}
