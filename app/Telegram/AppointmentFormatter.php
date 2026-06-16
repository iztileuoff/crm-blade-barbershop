<?php

namespace App\Telegram;

use App\Models\Appointment;
use App\Models\Client;

/**
 * Формирует тексты сообщений об записях для Telegram (HTML parse mode).
 * Все динамические значения экранируются через e().
 */
class AppointmentFormatter
{
    public static function services(Appointment $appointment): string
    {
        $names = $appointment->services->pluck('name')->filter()->implode(', ');

        return $names !== '' ? $names : 'Услуга';
    }

    /**
     * Строка для списка расписания мастера: «🕐 14:00 — Иван · Стрижка (Подтверждена)».
     */
    public static function barberLine(Appointment $appointment): string
    {
        return sprintf(
            '🕐 <b>%s</b> — %s · %s (%s)',
            e($appointment->starts_at->format('H:i')),
            e($appointment->client?->name ?? 'Клиент'),
            e(self::services($appointment)),
            e($appointment->status->label()),
        );
    }

    /**
     * Строка для списка записей клиента: «📅 16 июня 2026, 14:00 · Мастер · Стрижка — 50 000 сум».
     */
    public static function clientLine(Appointment $appointment): string
    {
        return sprintf(
            '📅 <b>%s, %s</b> · %s · %s — %s',
            e(Client::formatRussianDate($appointment->starts_at)),
            e($appointment->starts_at->format('H:i')),
            e($appointment->barber?->name ?? 'Мастер'),
            e(self::services($appointment)),
            e($appointment->formattedPrice),
        );
    }

    public static function newForBarber(Appointment $appointment): string
    {
        return "✂️ <b>Новая запись</b>\n\n".self::barberLine($appointment);
    }

    public static function cancelledForBarber(Appointment $appointment): string
    {
        return "❌ <b>Запись отменена</b>\n\n".self::barberLine($appointment);
    }

    public static function cancelledForClient(Appointment $appointment): string
    {
        return "❌ <b>Ваша запись отменена</b>\n\n".self::clientLine($appointment);
    }

    public static function reminderForClient(Appointment $appointment): string
    {
        return sprintf(
            "⏰ <b>Напоминание</b>\nВы записаны на <b>%s</b> к мастеру %s.\nЖдём вас в Blade Barbershop!",
            e($appointment->starts_at->format('H:i')),
            e($appointment->barber?->name ?? 'мастеру'),
        );
    }

    public static function reminderForBarber(Appointment $appointment): string
    {
        return "⏰ <b>Через 30 минут запись</b>\n\n".self::barberLine($appointment);
    }
}
