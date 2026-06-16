<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards;
use App\Telegram\TelegramLinker;
use SergiX44\Nutgram\Nutgram;

class StartHandler
{
    public function __invoke(Nutgram $bot, TelegramLinker $linker): void
    {
        $chatId = $bot->chatId();

        if ($chatId === null) {
            return;
        }

        if ($linker->findBarberUserByChat($chatId) !== null) {
            $bot->sendMessage('С возвращением! Выберите действие 👇', reply_markup: Keyboards::barberMenu());

            return;
        }

        if ($linker->findClientByChat($chatId) !== null) {
            $bot->sendMessage('С возвращением! Выберите действие 👇', reply_markup: Keyboards::clientMenu());

            return;
        }

        $bot->sendMessage(
            "👋 Добро пожаловать в <b>Blade Barbershop</b>!\n\n".
            'Чтобы продолжить, поделитесь своим номером телефона — мы найдём ваш профиль.',
            parse_mode: 'HTML',
            reply_markup: Keyboards::shareContact(),
        );
    }
}
