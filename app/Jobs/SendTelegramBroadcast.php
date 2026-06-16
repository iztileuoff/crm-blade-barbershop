<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramBroadcast implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $chatIds  Telegram chat id получателей
     * @param  string  $text  Текст в формате HTML
     */
    public function __construct(
        private readonly array $chatIds,
        private readonly string $text,
    ) {}

    public function handle(TelegramService $telegram): void
    {
        foreach ($this->chatIds as $chatId) {
            $telegram->sendMessage($chatId, $this->text);
        }
    }
}
