<?php

namespace App\Telegram\Handlers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Telegram\TelegramLinker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;

/**
 * Отмена записи клиентом прямо из бота (#76): инлайн-кнопка под записью шлёт
 * callback_data `cancel:{id}`. Payload — лишь подсказка; id из него никогда
 * не доверяем напрямую, владение и отменяемость перепроверяются в БД.
 */
class CancelAppointmentHandler
{
    public function __construct(private readonly TelegramLinker $linker) {}

    public function __invoke(Nutgram $bot, string $id): void
    {
        $chatId = $bot->chatId();
        $client = $chatId !== null ? $this->linker->findClientByChat($chatId) : null;

        if ($client === null) {
            $bot->answerCallbackQuery(text: __('telegram.not_linked'), show_alert: true);

            return;
        }

        // Server-side re-check: запись обязана принадлежать этому клиенту и
        // всё ещё быть отменяемой — callback_data сама по себе ничего не
        // доказывает (её мог отправить кто угодно с валидным chat id).
        //
        // Проверка и запись — под блокировкой строки в одной транзакции: Telegram
        // повторяет вебхук, если ответ задержался, и два одновременных callback'а
        // иначе оба прошли бы проверку и разослали по паре уведомлений.
        $appointment = DB::transaction(function () use ($id, $client): ?Appointment {
            $appointment = Appointment::query()
                ->whereKey((int) $id)
                ->where('client_id', $client->id)
                ->active()
                ->where('starts_at', '>', Carbon::now())
                ->lockForUpdate()
                ->first();

            if ($appointment !== null) {
                // Отмену инициировал сам клиент: наблюдателю не нужно слать ему
                // отдельное уведомление, ответ он получит прямо здесь.
                $appointment->cancelledByClient = true;
                $appointment->update(['status' => AppointmentStatus::Cancelled]);
            }

            return $appointment;
        });

        if ($appointment === null) {
            $bot->answerCallbackQuery(text: __('telegram.cancel_unavailable'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery(text: __('telegram.cancel_done_alert'));

        $bot->editMessageText(__('telegram.cancel_done_message'), parse_mode: 'HTML');
    }
}
