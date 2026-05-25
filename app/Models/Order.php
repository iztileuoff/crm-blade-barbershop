<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'client_id',
        'total_price',
        'note',
        'debt_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'integer',
            'debt_amount' => 'integer',
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

    public string $formattedTotal {
        get => number_format((int) $this->total_price, 0, '.', ' ').' сум';
    }

    public string $formattedDebt {
        get => number_format((int) ($this->debt_amount ?? 0), 0, '.', ' ').' сум';
    }

    public bool $hasDebt {
        get => ($this->debt_amount ?? 0) > 0;
    }
}
