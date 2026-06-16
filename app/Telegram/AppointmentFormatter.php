<?php

namespace App\Telegram;

use App\Models\Appointment;
use App\Models\Client;
use App\Support\NotificationTemplates;

/**
 * Формирует тексты сообщений об записях для Telegram (HTML parse mode).
 * Все динамические значения экранируются через e().
 */
class AppointmentFormatter
{
    /**
     * Экранированные значения для подстановки в шаблоны уведомлений.
     *
     * @return array<string, string>
     */
    private static function vars(Appointment $appointment): array
    {
        return [
            'time' => e($appointment->starts_at->format('H:i')),
            'date' => e(Client::formatRussianDate($appointment->starts_at)),
            'client' => e($appointment->client?->name ?? 'Клиент'),
            'barber' => e($appointment->barber?->name ?? 'Мастер'),
            'services' => e(self::services($appointment)),
            'price' => e($appointment->formattedPrice),
            'status' => e($appointment->status->label()),
        ];
    }

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
        return NotificationTemplates::render('tg_new_for_barber', self::vars($appointment));
    }

    public static function cancelledForBarber(Appointment $appointment): string
    {
        return NotificationTemplates::render('tg_cancelled_for_barber', self::vars($appointment));
    }

    public static function cancelledForClient(Appointment $appointment): string
    {
        return NotificationTemplates::render('tg_cancelled_for_client', self::vars($appointment));
    }

    public static function reminderForClient(Appointment $appointment): string
    {
        return NotificationTemplates::render('tg_reminder_for_client', self::vars($appointment));
    }

    public static function reminderForBarber(Appointment $appointment): string
    {
        return NotificationTemplates::render('tg_reminder_for_barber', self::vars($appointment));
    }
}
