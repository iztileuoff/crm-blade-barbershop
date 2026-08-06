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

    /**
     * Отмену сделал сам клиент из бота — ему уже ответили в том же чате, и
     * второе «ваша запись отменена» было бы шумом. Флаг живёт только в памяти
     * этого экземпляра и в базу не уезжает: он про то, кто нажал, а не про
     * состояние записи.
     */
    public bool $cancelledByClient = false;

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
        get => number_format((int) ($this->price ?? 0), 0, '.', ' ').' '.__('common.currency');
    }

    /**
     * Непогашенный остаток долга — то, что клиент ещё должен.
     */
    public string $formattedDebt {
        get => number_format($this->outstandingDebt, 0, '.', ' ').' '.__('common.currency');
    }

    public bool $hasDebt {
        get => $this->outstandingDebt > 0;
    }

    public function kassaTotalAmount(): int
    {
        return (int) ($this->price ?? 0);
    }

    /**
     * Процент мастера по этой записи: снимок, зафиксированный при завершении.
     */
    public function salaryPercent(?int $fallbackPercent = null): int
    {
        return (int) ($this->salary_percent ?? $fallbackPercent ?? $this->barber?->salary_percent ?? 0);
    }

    /**
     * Доля мастера по этой записи — от денег, полученных при самом визите.
     *
     * Единственное место, где живёт формула зарплаты: и дашборд, и Telegram
     * обязаны звать её, иначе мастеру показывают одну сумму, а начисляют другую.
     *
     * Долю с ПОГАШЕНИЙ эта величина не включает: погашение — отдельная операция
     * своего дня, и доля с него начисляется в день платежа
     * ({@see DebtPayment::salaryShare()}). Так суммы по дням складываются в
     * месяц, а закрытые периоды не пересчитываются задним числом.
     *
     * @param  int|null  $fallbackPercent  процент, если у записи нет своего снимка
     */
    public function salaryShare(?int $fallbackPercent = null): int
    {
        return (int) round($this->receivedAmount * $this->salaryPercent($fallbackPercent) / 100);
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
