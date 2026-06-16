<?php

namespace App\Http\Controllers;

use SergiX44\Nutgram\Nutgram;

class TelegramWebhookController extends Controller
{
    /**
     * Точка входа Telegram webhook. Nutgram сам проверяет secret_token
     * (safe_mode) и обрабатывает входящее обновление.
     */
    public function __invoke(Nutgram $bot): void
    {
        $bot->run();
    }
}
