<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards;
use App\Telegram\LinkOutcome;
use App\Telegram\TelegramLinker;
use SergiX44\Nutgram\Nutgram;

class ContactHandler
{
    public function __invoke(Nutgram $bot, TelegramLinker $linker): void
    {
        $chatId = $bot->chatId();
        $contact = $bot->message()?->contact;

        if ($chatId === null || $contact === null) {
            return;
        }

        // Принимаем только собственный контакт пользователя, не пересланный чужой.
        if ($contact->user_id !== null && $contact->user_id !== $bot->userId()) {
            $bot->sendMessage('Пожалуйста, отправьте именно свой номер кнопкой ниже.', reply_markup: Keyboards::shareContact());

            return;
        }

        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        $outcome = $linker->linkByPhone($chatId, $contact->phone_number, $name);

        match ($outcome) {
            LinkOutcome::BarberLinked => $bot->sendMessage(
                '✅ Профиль мастера привязан. Теперь вам доступно расписание и заработок.',
                reply_markup: Keyboards::barberMenu(),
            ),
            LinkOutcome::ClientLinked => $bot->sendMessage(
                '✅ Профиль найден! Здесь ваши записи, история и напоминания.',
                reply_markup: Keyboards::clientMenu(),
            ),
            LinkOutcome::ClientCreated => $bot->sendMessage(
                "✅ Готово! Мы будем присылать напоминания о записях.\nЗаписи и история появятся после первого визита.",
                reply_markup: Keyboards::clientMenu(),
            ),
            LinkOutcome::InvalidPhone => $bot->sendMessage(
                '❌ Не удалось распознать номер. Он должен быть в формате 998XXXXXXXXX.',
                reply_markup: Keyboards::shareContact(),
            ),
        };
    }
}
