<?php

namespace App\Support;

use App\Console\Commands\SendUpcomingReminders;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\SmsService;
use App\Services\TelegramService;
use App\Telegram\AppointmentFormatter;
use Closure;

/**
 * Уведомить клиента о записи: Telegram, если чат привязан, иначе SMS-резерв.
 *
 * Единая лестница «Telegram → SMS» — раньше жила только внутри
 * {@see SendUpcomingReminders}, из-за чего отмена записи
 * доходила лишь до Telegram-привязанных клиентов (#76). Обе точки входа,
 * которым нужно достучаться до клиента, обязаны звать эту точку, а не
 * повторять решение «Telegram или SMS» на месте.
 */
class ClientAppointmentNotifier
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly SmsService $sms,
    ) {}

    /**
     * Напоминание за 30 минут до визита.
     */
    public function notifyReminder(Appointment $appointment): bool
    {
        return $this->send(
            $appointment,
            fn () => AppointmentFormatter::reminderForClient($appointment),
            'reminder',
            ['time' => $appointment->starts_at->format('H:i')],
            'reminder',
        );
    }

    /**
     * Запись отменена (салоном или самим клиентом через бота).
     */
    public function notifyCancelled(Appointment $appointment): bool
    {
        return $this->send(
            $appointment,
            fn () => AppointmentFormatter::cancelledForClient($appointment),
            'cancelled',
            [
                'time' => $appointment->starts_at->format('H:i'),
                'date' => Client::formatRussianDate($appointment->starts_at),
            ],
            'cancelled',
        );
    }

    /**
     * @param  Closure(): string  $telegramText  Строится лениво — не тратим шаблон,
     *                                           если уйдёт SMS.
     * @param  array<string, string|int>  $smsVars
     */
    private function send(Appointment $appointment, Closure $telegramText, string $smsType, array $smsVars, string $smsContext): bool
    {
        $client = $appointment->client;

        if ($client === null) {
            return false;
        }

        if ($client->telegram_chat_id !== null) {
            return $this->telegram->sendMessage($client->telegram_chat_id, $telegramText());
        }

        $message = NotificationTemplates::renderSms($smsType, $smsVars, $client->locale);

        return $this->sms->sendSms($client->phone, $message, $client->id, $smsContext);
    }
}
