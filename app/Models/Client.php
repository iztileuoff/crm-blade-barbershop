<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'birth_date',
        'notes',
        'last_visit_at',
        'last_retention_sent_at',
        'telegram_chat_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'last_visit_at' => 'datetime',
            'last_retention_sent_at' => 'datetime',
            'telegram_chat_id' => 'integer',
        ];
    }

    /**
     * Номер в формате "+998 90 123 45 67" (PHP 8.4 property hook).
     */
    public string $formattedPhone {
        get => self::formatPhone((string) $this->phone);
    }

    public string $formattedBirthDate {
        get => $this->birth_date ? self::formatRussianDate($this->birth_date) : '—';
    }

    public string $formattedLastVisit {
        get => $this->last_visit_at ? self::formatRussianDate($this->last_visit_at) : '—';
    }

    public static function formatRussianDate(Carbon $date): string
    {
        static $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];

        return "{$date->day} {$months[$date->month]} {$date->year}";
    }

    public static function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) !== 12) {
            return $phone;
        }

        return sprintf(
            '+%s %s %s %s %s',
            substr($digits, 0, 3),
            substr($digits, 3, 2),
            substr($digits, 5, 3),
            substr($digits, 8, 2),
            substr($digits, 10, 2),
        );
    }

    /**
     * Привести любой ввод (998..., +998..., 90 123-45-67) к формату "998XXXXXXXXX".
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '998')) {
            return $digits;
        }

        return null;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function latestAppointment(): HasOne
    {
        return $this->hasOne(Appointment::class)->latestOfMany('starts_at');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }
}
