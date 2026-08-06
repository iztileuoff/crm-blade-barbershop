<?php

namespace App\Models;

use Database\Factories\TelegramBroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBroadcast extends Model
{
    /** @use HasFactory<TelegramBroadcastFactory> */
    use HasFactory;

    /**
     * Аудитории, поддерживаемые формой рассылки — держим в одном месте,
     * чтобы компонент и модель не расходились в допустимых значениях.
     *
     * @var list<string>
     */
    public const AUDIENCES = ['clients', 'barbers', 'all'];

    protected $fillable = [
        'user_id',
        'audience',
        'message',
        'recipients_count',
        'sent_count',
        'failed_count',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
