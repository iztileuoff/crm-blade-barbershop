<?php

namespace App\Observers;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Telegram\AppointmentNotice;

class AppointmentObserver
{
    /**
     * Фиксируем процент мастера в момент завершения записи, чтобы изменение
     * процента в будущем не пересчитывало уже завершённые записи.
     */
    public function saving(Appointment $appointment): void
    {
        if ($appointment->status === AppointmentStatus::Completed && $appointment->salary_percent === null) {
            $appointment->salary_percent = $appointment->barber?->salary_percent;
        }
    }

    /**
     * Новая запись — уведомляем мастера в Telegram.
     */
    public function created(Appointment $appointment): void
    {
        $barberChatId = $appointment->barber?->user?->telegram_chat_id;

        if ($barberChatId !== null) {
            SendAppointmentNotification::dispatch(
                $barberChatId,
                $appointment->id,
                AppointmentNotice::NewForBarber,
            );
        }
    }

    /**
     * Запись отменена — уведомляем мастера и клиента.
     */
    public function updated(Appointment $appointment): void
    {
        if (! $appointment->wasChanged('status')) {
            return;
        }

        if ($appointment->status !== AppointmentStatus::Cancelled) {
            return;
        }

        $barberChatId = $appointment->barber?->user?->telegram_chat_id;

        if ($barberChatId !== null) {
            SendAppointmentNotification::dispatch(
                $barberChatId,
                $appointment->id,
                AppointmentNotice::CancelledForBarber,
            );
        }

        $clientChatId = $appointment->client?->telegram_chat_id;

        if ($clientChatId !== null) {
            SendAppointmentNotification::dispatch(
                $clientChatId,
                $appointment->id,
                AppointmentNotice::CancelledForClient,
            );
        }
    }
}
