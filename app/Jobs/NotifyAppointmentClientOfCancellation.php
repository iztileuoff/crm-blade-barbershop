<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Support\ClientAppointmentNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Уведомить клиента об отмене записи через лестницу «Telegram → SMS»
 * ({@see ClientAppointmentNotifier}). Отдельная очередь от
 * {@see SendAppointmentNotification}, потому что SMS-резерв клиенту нужен
 * заранее известный chat id не требует — решение принимается внутри воркера.
 */
class NotifyAppointmentClientOfCancellation implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $appointmentId) {}

    public function handle(ClientAppointmentNotifier $notifier): void
    {
        $appointment = Appointment::query()
            ->with(['client', 'barber', 'services'])
            ->find($this->appointmentId);

        if ($appointment === null) {
            return;
        }

        $notifier->notifyCancelled($appointment);
    }
}
