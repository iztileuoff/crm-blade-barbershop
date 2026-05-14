<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'birth_date',
        'last_visit_at',
        'last_retention_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'last_visit_at' => 'datetime',
            'last_retention_sent_at' => 'datetime',
        ];
    }

    /**
     * Номер в формате "+998 90 123 45 67" (PHP 8.4 property hook).
     */
    public string $formattedPhone {
        get => self::formatPhone((string) $this->phone);
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
}
