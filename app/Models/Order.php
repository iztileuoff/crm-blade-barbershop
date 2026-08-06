<?php

namespace App\Models;

use App\Enums\PaymentType;
use App\Models\Concerns\HasCashRegisterAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasCashRegisterAmounts;

    protected $fillable = [
        'client_id',
        'total_price',
        'note',
        'debt_amount',
        'payment_type',
        'cash_amount',
        'card_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'integer',
            'debt_amount' => 'integer',
            'payment_type' => PaymentType::class,
            'cash_amount' => 'integer',
            'card_amount' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function kassaTotalAmount(): int
    {
        return (int) ($this->total_price ?? 0);
    }

    public string $formattedTotal {
        get => number_format((int) $this->total_price, 0, '.', ' ').' сум';
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
}
