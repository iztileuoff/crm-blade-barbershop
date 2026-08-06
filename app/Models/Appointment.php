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

    /**
     * Непогашенный остаток долга — то, что клиент ещё должен.
     */
    public string $formattedDebt {
        get => number_format($this->outstandingDebt, 0, '.', ' ').' сум';
    }

    public bool $hasDebt {
        get => $this->outstandingDebt > 0;
    }

    public function kassaTotalAmount(): int
    {
        return (int) ($this->price ?? 0);
    }

    /**
     * Деньги по этой записи, попавшие в кассу внутри рассматриваемого периода:
     * полученное при оказании услуги плюс погашения долга, принятые в том же
     * периоде.
     *
     * Слагаемое с погашениями берётся из withSum('... as debt_collected_in_period').
     * Без него остаётся только полученное при визите — это и нужно там, где
     * период не задан.
     */
    public int $collectedInPeriod {
        get => $this->receivedAmount
            + (int) ($this->attributes['debt_collected_in_period'] ?? 0);
    }

    /**
     * Доля мастера по этой записи.
     *
     * Единственное место, где живёт формула зарплаты: и дашборд, и Telegram
     * обязаны звать её, иначе мастеру показывают одну сумму, а начисляют другую.
     * База — ПОЛУЧЕННЫЕ деньги: услуга, ушедшая в долг, приносит долю только
     * после того, как деньги дошли до кассы, и только если их собрали внутри
     * того же периода — иначе пересчитывались бы уже закрытые расчётные месяцы.
     *
     * @param  int|null  $fallbackPercent  процент, если у записи нет своего снимка
     */
    public function salaryShare(?int $fallbackPercent = null): int
    {
        $percent = $this->salary_percent ?? $fallbackPercent ?? $this->barber?->salary_percent ?? 0;

        return (int) round($this->collectedInPeriod * $percent / 100);
    }

    /**
     * Погашения долга, принятые внутри периода, — слагаемое для базы зарплаты.
     */
    public function scopeWithDebtCollectedBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->withSum([
            'debtPayments as debt_collected_in_period' => fn ($q) => $q->whereBetween('paid_at', [$from, $to]),
        ], 'amount');
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
