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
        // Пустой user_id тоже отклоняем: кнопка «поделиться контактом» его всегда
        // проставляет, а без него привязка ушла бы к любому владельцу номера —
        // вместе с его заработком и расписанием.
        if ($contact->user_id === null || $contact->user_id !== $bot->userId()) {
            $bot->sendMessage(__('telegram.contact_wrong_owner'), reply_markup: Keyboards::shareContact());

            return;
        }

        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        $outcome = $linker->linkByPhone($chatId, $contact->phone_number, $name);

        match ($outcome) {
            LinkOutcome::BarberLinked => $bot->sendMessage(
                __('telegram.contact_barber_linked'),
                reply_markup: Keyboards::barberMenu(),
            ),
            LinkOutcome::ClientLinked => $bot->sendMessage(
                __('telegram.contact_client_linked'),
                reply_markup: Keyboards::clientMenu(),
            ),
            LinkOutcome::ClientCreated => $bot->sendMessage(
                __('telegram.contact_client_created'),
                reply_markup: Keyboards::clientMenu(),
            ),
            LinkOutcome::InvalidPhone => $bot->sendMessage(
                __('telegram.contact_invalid_phone'),
                reply_markup: Keyboards::shareContact(),
            ),
        };
    }
}
