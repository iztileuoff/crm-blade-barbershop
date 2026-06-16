<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class TelegramService
{
    /**
     * Отправить сообщение в произвольный чат вне контекста обновления.
     *
     * Экземпляр Nutgram резолвится лениво — только при наличии токена, чтобы
     * команды (например, напоминания) не падали, когда бот не настроен.
     *
     * @param  int  $chatId  Telegram chat id (см. users/clients.telegram_chat_id)
     * @param  string  $text  Текст в формате HTML (динамические значения должны быть экранированы)
     */
    public function sendMessage(int $chatId, string $text): bool
    {
        if ((string) config('nutgram.token') === '') {
            Log::warning('Telegram-сообщение не отправлено: TELEGRAM_TOKEN не настроен.', [
                'chat_id' => $chatId,
            ]);

            return false;
        }

        try {
            app(Nutgram::class)->sendMessage(
                text: $text,
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Ошибка отправки Telegram-сообщения', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
