<?php

namespace App\Models;

use Database\Factories\SmsMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    /** @use HasFactory<SmsMessageFactory> */
    use HasFactory;

    /**
     * Финальные статусы доставки Eskiz — их больше не нужно опрашивать.
     *
     * @var list<string>
     */
    public const FINAL_DELIVERY_STATUSES = ['delivered', 'undelivered', 'rejected'];

    protected $fillable = [
        'client_id',
        'phone',
        'message',
        'status',
        'context',
        'eskiz_message_id',
        'parts',
        'delivery_status',
        'status_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'parts' => 'integer',
            'status_checked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isDelivered(): bool
    {
        return $this->delivery_status === 'delivered';
    }
}
