<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\TelegramService;
use App\Support\ClientAppointmentNotifier;
use App\Telegram\AppointmentFormatter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-upcoming-reminders')]
#[Description('Отправляет напоминания за 30 минут до записи (Telegram, SMS как резерв) клиенту и мастеру')]
class SendUpcomingReminders extends Command
{
    public function handle(ClientAppointmentNotifier $notifier, TelegramService $telegram): int
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

            $notified = $notifier->notifyReminder($appointment);

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
