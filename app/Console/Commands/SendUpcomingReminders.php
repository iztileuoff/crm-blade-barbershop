<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\SmsService;
use App\Services\TelegramService;
use App\Support\NotificationTemplates;
use App\Telegram\AppointmentFormatter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-upcoming-reminders')]
#[Description('Отправляет напоминания за 30 минут до записи (Telegram, SMS как резерв) клиенту и мастеру')]
class SendUpcomingReminders extends Command
{
    public function handle(SmsService $sms, TelegramService $telegram): int
    {
        $target = Carbon::now()->addMinutes(30);
        $window = [$target->copy()->subSeconds(30), $target->copy()->addSeconds(30)];

        $appointments = Appointment::query()
            ->with(['client', 'barber.user', 'services'])
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
            ->where('notified_30min', false)
            ->whereBetween('starts_at', $window)
            ->get();

        foreach ($appointments as $appointment) {
            $client = $appointment->client;

            if (! $client) {
                continue;
            }

            $notified = $this->notifyClient($appointment, $sms, $telegram);

            if (! $notified) {
                continue;
            }

            $appointment->forceFill(['notified_30min' => true])->save();
            $this->info("Напоминание отправлено клиенту {$client->phone}");

            $this->notifyBarber($appointment, $telegram);
        }

        return self::SUCCESS;
    }

    /**
     * Клиенту: Telegram, если привязан, иначе SMS.
     */
    private function notifyClient(Appointment $appointment, SmsService $sms, TelegramService $telegram): bool
    {
        $client = $appointment->client;

        if ($client->telegram_chat_id !== null) {
            return $telegram->sendMessage(
                $client->telegram_chat_id,
                AppointmentFormatter::reminderForClient($appointment),
            );
        }

        $message = NotificationTemplates::renderSms('reminder', [
            'time' => $appointment->starts_at->format('H:i'),
        ]);

        return $sms->sendSms($client->phone, $message, $client->id, 'reminder');
    }

    /**
     * Мастеру: Telegram, если привязан.
     */
    private function notifyBarber(Appointment $appointment, TelegramService $telegram): void
    {
        $chatId = $appointment->barber?->user?->telegram_chat_id;

        if ($chatId !== null) {
            $telegram->sendMessage($chatId, AppointmentFormatter::reminderForBarber($appointment));
        }
    }
}
