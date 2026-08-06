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
            $bot->sendMessage(__('telegram.welcome_back'), reply_markup: Keyboards::barberMenu());

            return;
        }

        if ($linker->findClientByChat($chatId) !== null) {
            $bot->sendMessage(__('telegram.welcome_back'), reply_markup: Keyboards::clientMenu());

            return;
        }

        $bot->sendMessage(
            __('telegram.welcome_new'),
            parse_mode: 'HTML',
            reply_markup: Keyboards::shareContact(),
        );
    }
}
