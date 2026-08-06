<?php

namespace App\Models;

use App\Enums\PaymentType;
use Database\Factories\DebtPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Погашение долга по записи или продаже.
 *
 * Попадает в кассу того дня, когда деньги реально приняли (paid_at),
 * а не того, когда была оказана услуга.
 */
class DebtPayment extends Model
{
    /** @use HasFactory<DebtPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'payable_type',
        'payable_id',
        'amount',
        'payment_type',
        'paid_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'payment_type' => PaymentType::class,
            'paid_at' => 'datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Часть платежа, попавшая в наличные.
     */
    public int $cashReceived {
        get => $this->payment_type === PaymentType::Card ? 0 : $this->amount;
    }

    /**
     * Часть платежа, попавшая на карту.
     */
    public int $cardReceived {
        get => $this->payment_type === PaymentType::Card ? $this->amount : 0;
    }

    public function scopeBetweenDates(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('paid_at', [$from, $to]);
    }
}
