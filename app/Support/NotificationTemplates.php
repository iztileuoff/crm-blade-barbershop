<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Тексты уведомлений.
 *
 * Telegram-шаблоны редактируются админом (хранятся в settings под "template_{key}",
 * иначе берётся DEFAULTS). SMS-тексты зафиксированы в коде (SMS) и не редактируются —
 * Eskiz доставляет только согласованные тексты; выбирается лишь язык (sms_locale).
 */
class NotificationTemplates
{
    /**
     * Тексты по умолчанию. Плейсхолдеры в фигурных скобках заменяются при отправке.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        // Telegram (HTML parse mode)
        'tg_new_for_barber' => "✂️ <b>Новая запись</b>\n\n🕐 <b>{time}</b> — {client} · {services} ({status})",
        'tg_cancelled_for_barber' => "❌ <b>Запись отменена</b>\n\n🕐 <b>{time}</b> — {client} · {services} ({status})",
        'tg_cancelled_for_client' => "❌ <b>Ваша запись отменена</b>\n\n📅 <b>{date}, {time}</b> · {barber} · {services} — {price}",
        'tg_reminder_for_client' => "⏰ <b>Напоминание</b>\nВы записаны на <b>{time}</b> к мастеру {barber}.\nЖдём вас в Blade Barbershop!",
        'tg_reminder_for_barber' => "⏰ <b>Через 30 минут запись</b>\n\n🕐 <b>{time}</b> — {client} · {services} ({status})",
    ];

    /**
     * Поддерживаемые языки SMS и язык по умолчанию.
     *
     * @var list<string>
     */
    public const SMS_LOCALES = ['ru', 'uz', 'kaa'];

    public const SMS_DEFAULT_LOCALE = 'ru';

    /**
     * Зафиксированные, одобренные в Eskiz тексты SMS. Не редактируются из админки:
     * Eskiz доставляет только заранее согласованные тексты, поэтому строки обязаны
     * совпадать с кабинетом Eskiz символ в символ. Плейсхолдер {time} — переменная.
     *
     * @var array<string, array<string, string>>
     */
    public const SMS = [
        'reminder' => [
            'ru' => 'Напоминаем, вы записаны на {time}. Ждем вас в Blade Barbershop!',
            'uz' => "Eslatma: Blade Barbershop'da soat {time} ga yozilgansiz. Sizni kutamiz!",
            'kaa' => 'Esletpe! Jazılıwıńız waqtı: {time}. Blade Barbershop sizdi kútedi!',
        ],
        'retention' => [
            'ru' => 'Давно вас не видели! Время обновить стрижку. Blade Barbershop.',
            'uz' => "Soch oldirganingizga ancha bo'ldi. Yangilash vaqti keldi! Blade Barbershop sizni kutadi.",
            'kaa' => 'Uzaq waqıt kelmedińiz! Shash aldırıw waqtı keldi. Blade Barbershop.',
        ],
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

    /**
     * Текущий язык SMS (из настроек), с откатом на язык по умолчанию.
     */
    public static function smsLocale(): string
    {
        $locale = Setting::get('sms_locale', self::SMS_DEFAULT_LOCALE);

        return in_array($locale, self::SMS_LOCALES, true) ? $locale : self::SMS_DEFAULT_LOCALE;
    }

    /**
     * Зафиксированный текст SMS указанного типа на текущем языке
     * с подстановкой плейсхолдеров.
     *
     * @param  array<string, string|int>  $vars
     */
    public static function renderSms(string $type, array $vars = []): string
    {
        $locale = self::smsLocale();
        $text = self::SMS[$type][$locale] ?? self::SMS[$type][self::SMS_DEFAULT_LOCALE] ?? '';

        foreach ($vars as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }
}
