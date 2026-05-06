<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\SmsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-upcoming-reminders')]
#[Description('Отправляет SMS-напоминания клиентам за 30 минут до записи')]
class SendUpcomingReminders extends Command
{
    public function handle(SmsService $sms): int
    {
        $target = Carbon::now()->addMinutes(30);
        $window = [$target->copy()->subSeconds(30), $target->copy()->addSeconds(30)];

        $appointments = Appointment::query()
            ->with(['client', 'barber'])
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
            ->where('notified_30min', false)
            ->whereBetween('starts_at', $window)
            ->get();

        foreach ($appointments as $appointment) {
            $client = $appointment->client;

            if (! $client) {
                continue;
            }

            $message = sprintf(
                'Напоминаем, вы записаны на %s. Ждем вас в Blade Barbershop!',
                $appointment->starts_at->format('H:i'),
            );

            if ($sms->sendSms($client->phone, $message)) {
                $appointment->forceFill(['notified_30min' => true])->save();
                $this->info("SMS отправлено клиенту {$client->phone}");
            }
        }

        return self::SUCCESS;
    }
}
