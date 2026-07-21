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
        if ($appointment->status === AppointmentStatus::Completed) {
            $this->touchClientLastVisit($appointment);
        }

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
     * Смена статуса: завершение — обновляем «последний визит» клиента (нужно для
     * SMS-удержания); отмена — уведомляем мастера и клиента.
     */
    public function updated(Appointment $appointment): void
    {
        if (! $appointment->wasChanged('status')) {
            return;
        }

        if ($appointment->status === AppointmentStatus::Completed) {
            $this->touchClientLastVisit($appointment);

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

    /**
     * Отметить дату визита клиента по завершённой записи. Двигаем только вперёд,
     * чтобы правка старой записи не откатывала более свежий визит.
     */
    private function touchClientLastVisit(Appointment $appointment): void
    {
        $client = $appointment->client;
        $visitedAt = $appointment->starts_at;

        if ($client === null || $visitedAt === null) {
            return;
        }

        if ($client->last_visit_at === null || $client->last_visit_at->lt($visitedAt)) {
            $client->forceFill(['last_visit_at' => $visitedAt])->save();
        }
    }
}
