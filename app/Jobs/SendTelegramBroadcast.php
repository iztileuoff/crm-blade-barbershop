<?php

namespace App\Jobs;

use App\Models\TelegramBroadcast;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendTelegramBroadcast implements ShouldQueue
{
    use Queueable;

    /**
     * Размер пачки. 25 сообщений уходят за считанные секунды, поэтому задание
     * гарантированно укладывается в retry_after очереди (90 с по умолчанию) —
     * иначе очередь сочла бы его зависшим и выдала второму воркеру, а уже
     * отправленные клиенты получили бы рассылку повторно.
     */
    public const CHUNK = 25;

    /**
     * Повторов нет намеренно: отправка — не идемпотентная операция, и вторая
     * попытка означала бы второе сообщение живому человеку. Провал пачки
     * честнее записать в неудачи, чем пытаться ещё раз.
     */
    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  int  $broadcastId  Запись telegram_broadcasts, созданная до диспатча
     * @param  list<int>  $chatIds  Telegram chat id получателей этой пачки
     * @param  string  $text  Текст в формате HTML
     */
    public function __construct(
        private readonly int $broadcastId,
        private readonly array $chatIds,
        private readonly string $text,
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->chatIds as $chatId) {
            if ($telegram->sendMessage($chatId, $this->text)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $this->recordProgress($sent, $failed);
    }

    /**
     * Упавшая пачка тоже закрывает свою часть счётчика: иначе рассылка навсегда
     * осталась бы «в процессе» и никто не узнал бы, чем она кончилась.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordProgress(0, count($this->chatIds));
    }

    /**
     * Пачки идут параллельно, поэтому счётчики двигаются под блокировкой строки,
     * а завершённой рассылка становится тогда, когда отчитались все получатели.
     */
    private function recordProgress(int $sent, int $failed): void
    {
        DB::transaction(function () use ($sent, $failed): void {
            $broadcast = TelegramBroadcast::query()
                ->lockForUpdate()
                ->find($this->broadcastId);

            if ($broadcast === null) {
                return;
            }

            $broadcast->sent_count += $sent;
            $broadcast->failed_count += $failed;

            if ($broadcast->sent_count + $broadcast->failed_count >= $broadcast->recipients_count) {
                $broadcast->completed_at = now();
            }

            $broadcast->save();
        });
    }
}
