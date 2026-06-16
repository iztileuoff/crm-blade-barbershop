<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Редактируемые тексты уведомлений (SMS и Telegram).
 *
 * Значения хранятся в таблице settings под ключом "template_{key}".
 * Если админ ничего не сохранял — используется текст по умолчанию.
 */
class NotificationTemplates
{
    /**
     * Тексты по умолчанию. Плейсхолдеры в фигурных скобках заменяются при отправке.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        // SMS (plain text)
        'sms_reminder' => 'Напоминаем, вы записаны на {time}. Ждем вас в Blade Barbershop!',
        'sms_retention' => 'Прошло {days} дней с вашего визита. Пора обновить стрижку!',

        // Telegram (HTML parse mode)
        'tg_new_for_barber' => "✂️ <b>Новая запись</b>\n\n🕐 <b>{time}</b> — {client} · {services} ({status})",
        'tg_cancelled_for_barber' => "❌ <b>Запись отменена</b>\n\n🕐 <b>{time}</b> — {client} · {services} ({status})",
        'tg_cancelled_for_client' => "❌ <b>Ваша запись отменена</b>\n\n📅 <b>{date}, {time}</b> · {barber} · {services} — {price}",
        'tg_reminder_for_client' => "⏰ <b>Напоминание</b>\nВы записаны на <b>{time}</b> к мастеру {barber}.\nЖдём вас в Blade Barbershop!",
        'tg_reminder_for_barber' => "⏰ <b>Через 30 минут запись</b>\n\n🕐 <b>{time}</b> — {client} · {services} ({status})",
    ];

    /**
     * Текущий текст шаблона (сохранённый админом либо значение по умолчанию).
     */
    public static function get(string $key): string
    {
        $stored = Setting::get('template_'.$key);

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        return self::DEFAULTS[$key] ?? '';
    }

    public static function set(string $key, ?string $value): void
    {
        Setting::set('template_'.$key, $value ?: null);
    }

    /**
     * Подставить значения в шаблон.
     *
     * @param  array<string, string|int>  $vars
     */
    public static function render(string $key, array $vars): string
    {
        $text = self::get($key);

        foreach ($vars as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }
}
