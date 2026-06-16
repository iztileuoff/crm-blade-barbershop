<?php

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class Keyboards
{
    public const BARBER_TODAY = '📅 Сегодня';

    public const BARBER_TOMORROW = '🗓 Завтра';

    public const BARBER_EARNINGS = '💰 Заработок';

    public const CLIENT_APPOINTMENTS = '📅 Мои записи';

    public const CLIENT_HISTORY = '🕓 История';

    public const CLIENT_DEBT = '💳 Долг';

    public static function shareContact(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make('📱 Поделиться номером', request_contact: true));
    }

    public static function barberMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(
                KeyboardButton::make(self::BARBER_TODAY),
                KeyboardButton::make(self::BARBER_TOMORROW),
            )
            ->addRow(KeyboardButton::make(self::BARBER_EARNINGS));
    }

    public static function clientMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(KeyboardButton::make(self::CLIENT_APPOINTMENTS))
            ->addRow(
                KeyboardButton::make(self::CLIENT_HISTORY),
                KeyboardButton::make(self::CLIENT_DEBT),
            );
    }
}
