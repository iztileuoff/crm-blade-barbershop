<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\TelegramService;
use App\Telegram\AppointmentFormatter;
use App\Telegram\AppointmentNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAppointmentNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $chatId,
        private readonly int $appointmentId,
        private readonly AppointmentNotice $notice,
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $appointment = Appointment::query()
            ->with(['client', 'barber', 'services'])
            ->find($this->appointmentId);

        if ($appointment === null) {
            return;
        }

        $text = match ($this->notice) {
            AppointmentNotice::NewForBarber => AppointmentFormatter::newForBarber($appointment),
            AppointmentNotice::CancelledForBarber => AppointmentFormatter::cancelledForBarber($appointment),
            AppointmentNotice::CancelledForClient => AppointmentFormatter::cancelledForClient($appointment),
        };

        $telegram->sendMessage($this->chatId, $text);
    }
}
