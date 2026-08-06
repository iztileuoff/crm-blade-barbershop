<?php

namespace App\Telegram;

use App\Support\NotificationTemplates;
use App\Telegram\Middleware\SetBotLocale;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

/**
 * Тексты кнопок живут в lang/{ru,uz,kaa}/telegram.php и рисуются на языке
 * текущего чата (см. {@see SetBotLocale}). Reply-кнопки
 * при этом регистрируются в routes/telegram.php один раз при загрузке — раньше,
 * чем известен язык конкретного собеседника, — поэтому {@see labelVariants()}
 * отдаёт текст сразу на всех поддерживаемых языках: маршрут обязан слушать
 * любой из них (#76).
 */
class Keyboards
{
    public const BARBER_TODAY = 'kb_today';

    public const BARBER_TOMORROW = 'kb_tomorrow';

    public const BARBER_EARNINGS = 'kb_earnings';

    public const CLIENT_APPOINTMENTS = 'kb_appointments';

    public const CLIENT_HISTORY = 'kb_history';

    public const CLIENT_DEBT = 'kb_debt';

    public static function shareContact(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make(self::label('kb_share_contact'), request_contact: true));
    }

    public static function barberMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(
                KeyboardButton::make(self::label(self::BARBER_TODAY)),
                KeyboardButton::make(self::label(self::BARBER_TOMORROW)),
            )
            ->addRow(KeyboardButton::make(self::label(self::BARBER_EARNINGS)));
    }

    public static function clientMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(KeyboardButton::make(self::label(self::CLIENT_APPOINTMENTS)))
            ->addRow(
                KeyboardButton::make(self::label(self::CLIENT_HISTORY)),
                KeyboardButton::make(self::label(self::CLIENT_DEBT)),
            );
    }

    /**
     * Инлайн-кнопка «Отменить» под конкретной записью клиента (#76). Id уходит
     * в callback_data только как подсказка — хэндлер обязан перепроверить
     * владение и статус записи на сервере, а не доверять этому payload.
     */
    public static function cancelAppointment(int $appointmentId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(self::label('cancel_button'), callback_data: "cancel:{$appointmentId}"),
        );
    }

    /**
     * Текст на языке текущего чата (locale уже выставлен мидлваром до вызова
     * хэндлера).
     */
    public static function label(string $key): string
    {
        return __('telegram.'.$key);
    }

    /**
     * Тот же текст на каждом поддерживаемом языке — для регистрации маршрута,
     * которому заранее не известен язык собеседника.
     *
     * @return list<string>
     */
    public static function labelVariants(string $key): array
    {
        return collect(NotificationTemplates::SMS_LOCALES)
            ->map(fn (string $locale) => trans('telegram.'.$key, [], $locale))
            ->unique()
            ->values()
            ->all();
    }
}
