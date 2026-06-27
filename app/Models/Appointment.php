<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentType;
use App\Models\Concerns\HasCashRegisterAmounts;
use App\Observers\AppointmentObserver;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

#[ObservedBy(AppointmentObserver::class)]
class Appointment extends Model
{
    use HasCashRegisterAmounts;

    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'barber_id',
        'starts_at',
        'ends_at',
        'status',
        'price',
        'salary_percent',
        'note',
        'notified_30min',
        'payment_type',
        'cash_amount',
        'card_amount',
        'debt_amount',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'price' => 'integer',
            'salary_percent' => 'integer',
            'notified_30min' => 'boolean',
            'payment_type' => PaymentType::class,
            'cash_amount' => 'integer',
            'card_amount' => 'integer',
            'debt_amount' => 'integer',
        ];
    }

    public string $formattedPrice {
        get => number_format((int) ($this->price ?? 0), 0, '.', ' ').' сум';
    }

    public string $formattedDebt {
        get => number_format((int) ($this->debt_amount ?? 0), 0, '.', ' ').' сум';
    }

    public bool $hasDebt {
        get => ($this->debt_amount ?? 0) > 0;
    }

    public function kassaTotalAmount(): int
    {
        return (int) ($this->price ?? 0);
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
