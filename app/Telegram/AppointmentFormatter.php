<?php

namespace App\Telegram;

use App\Models\Appointment;
use App\Models\Client;
use App\Support\NotificationTemplates;

/**
 * Формирует тексты сообщений об записях для Telegram (HTML parse mode).
 * Все динамические значения экранируются через {@see TelegramHtml::escape()},
 * а не через хелпер Blade: он кодирует апостроф, и имя доезжает до клиента
 * как «O&#039;ktam».
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
            'time' => TelegramHtml::escape($appointment->starts_at->format('H:i')),
            'date' => TelegramHtml::escape(Client::formatRussianDate($appointment->starts_at)),
            'client' => TelegramHtml::escape($appointment->client?->name ?? 'Клиент'),
            'barber' => TelegramHtml::escape($appointment->barber?->name ?? 'Мастер'),
            'services' => TelegramHtml::escape(self::services($appointment)),
            'price' => TelegramHtml::escape($appointment->formattedPrice),
            'status' => TelegramHtml::escape($appointment->status->label()),
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
            TelegramHtml::escape($appointment->starts_at->format('H:i')),
            TelegramHtml::escape($appointment->client?->name ?? 'Клиент'),
            TelegramHtml::escape(self::services($appointment)),
            TelegramHtml::escape($appointment->status->label()),
        );
    }

    /**
     * Строка для списка записей клиента: «📅 16 июня 2026, 14:00 · Мастер · Стрижка — 50 000 сум».
     */
    public static function clientLine(Appointment $appointment): string
    {
        return sprintf(
            '📅 <b>%s, %s</b> · %s · %s — %s',
            TelegramHtml::escape(Client::formatRussianDate($appointment->starts_at)),
            TelegramHtml::escape($appointment->starts_at->format('H:i')),
            TelegramHtml::escape($appointment->barber?->name ?? 'Мастер'),
            TelegramHtml::escape(self::services($appointment)),
            TelegramHtml::escape($appointment->formattedPrice),
        );
    }

    public static function newForBarber(Appointment $appointment): string
    {
        return NotificationTemplates::render('tg_new_for_barber', self::vars($appointment));
    }

    public static function newForClient(Appointment $appointment): string
    {
        return NotificationTemplates::render('tg_new_for_client', self::vars($appointment));
    }

    public static function confirmedForClient(Appointment $appointment): string
    {
        return NotificationTemplates::render('tg_confirmed_for_client', self::vars($appointment));
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
