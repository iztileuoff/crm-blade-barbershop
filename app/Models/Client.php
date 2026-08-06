<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
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
        get => $this->birth_date ? self::formatLocalizedDate($this->birth_date) : '—';
    }

    public string $formattedLastVisit {
        get => $this->last_visit_at ? self::formatLocalizedDate($this->last_visit_at) : '—';
    }

    /**
     * Дата в формате «16 июня 2026» с названием месяца на активном языке интерфейса.
     */
    public static function formatLocalizedDate(Carbon $date): string
    {
        /** @var array<int, string> $months */
        $months = (array) __('common.month');
        $month = $months[$date->month] ?? $date->month;

        return "{$date->day} {$month} {$date->year}";
    }

    /**
     * Дата с русскими названиями месяцев — для Telegram-уведомлений (русскоязычных).
     */
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
     * Цифры поискового терма, пригодные для матчинга по колонке `phone`.
     *
     * Интерфейс везде показывает «+998 90 123 45 67», а в колонке лежат голые
     * цифры: скопированный из UI или Telegram номер обязан находиться. Ведущее
     * «998» срезаем — поиск идёт подстрокой, поэтому это только расширяет
     * совпадения, но не теряет их. Меньше трёх цифр не ищем: одиночная цифра
     * из имени вроде «Али 2» иначе подтянет пол-базы.
     */
    public static function searchDigits(string $term): ?string
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        if (str_starts_with($digits, '998')) {
            $digits = substr($digits, 3);
        }

        return strlen($digits) >= 3 ? $digits : null;
    }

    /**
     * Поиск клиента по имени или телефону в любом видимом формате.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $digits = self::searchDigits($term);

        return $query->where(function (Builder $q) use ($term, $digits): void {
            $q->where('name', 'like', '%'.$term.'%');

            if ($digits !== null) {
                $q->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
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
