<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Confirmed => 'Подтверждена',
            self::Completed => 'Завершена',
            self::Cancelled => 'Отменена',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-800',
            self::Confirmed => 'bg-blue-100 text-blue-800',
            self::Completed => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-rose-100 text-rose-800',
        };
    }
}
