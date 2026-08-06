<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /**
     * Порог «мало на складе»: одним числом живут и фильтр остатков, и цвет
     * строки в таблице — иначе они разъезжаются.
     */
    public const LOW_STOCK = 5;

    protected $fillable = [
        'name',
        'stock',
        'selling_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'selling_price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public string $formattedPrice {
        get => number_format((int) $this->selling_price, 0, '.', ' ').' сум';
    }
}
