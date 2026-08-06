<?php

namespace App\Observers;

use App\Enums\AppointmentStatus;
use App\Jobs\NotifyAppointmentClientOfCancellation;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Telegram\AppointmentNotice;

class AppointmentObserver
{
    /**
     * Фиксируем процент мастера в момент завершения записи, чтобы изменение
     * процента в будущем не пересчитывало уже завершённые записи.
     *
     * Процент перефиксируется, если у завершённой записи сменили мастера: иначе
     * снимок «процента мастера» превращается в снимок «процента чужого мастера»
     * и зарплата начисляется по ставке того, кто услугу не оказывал.
     */
    public function saving(Appointment $appointment): void
    {
        if ($appointment->status !== AppointmentStatus::Completed) {
            return;
        }

        if ($appointment->isDirty('barber_id')) {
            // Связь могла быть загружена до смены barber_id — иначе возьмём прежнего мастера.
            $appointment->unsetRelation('barber');
            $appointment->salary_percent = $appointment->barber?->salary_percent;

            return;
        }

        if ($appointment->salary_percent === null) {
            $appointment->salary_percent = $appointment->barber?->salary_percent;
        }
    }

    /**
     * Новая запись — уведомляем мастера и (если чат уже привязан) клиента в
     * Telegram. Клиенту без Telegram здесь ничего не уходит: для «новой записи»
     * SMS-резерва нет, только у отмены (#76) — это активная связь с салоном,
     * а не удобство.
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

        $clientChatId = $appointment->client?->telegram_chat_id;

        // Запись, заведённую админом, статус-кво не требует подтверждать: она
        // создаётся сразу Confirmed, и «мы свяжемся для подтверждения» описывало
        // бы не то, что произошло. Дальше статус уже не меняется, так что
        // второго шанса сказать правду не будет.
        $notice = match ($appointment->status) {
            AppointmentStatus::Pending => AppointmentNotice::NewForClient,
            AppointmentStatus::Confirmed => AppointmentNotice::ConfirmedForClient,
            default => null,
        };

        if ($clientChatId !== null && $notice !== null) {
            SendAppointmentNotification::dispatch(
                $clientChatId,
                $appointment->id,
                $notice,
            );
        }
    }

    /**
     * Смена статуса: завершение — обновляем «последний визит» клиента (нужно для
     * SMS-удержания); подтверждение — уведомляем клиента; отмена — уведомляем
     * мастера и клиента (клиента — через лестницу Telegram → SMS, чтобы узнавали
     * даже без привязанного чата).
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

        if ($appointment->status === AppointmentStatus::Confirmed) {
            $clientChatId = $appointment->client?->telegram_chat_id;

            if ($clientChatId !== null) {
                SendAppointmentNotification::dispatch(
                    $clientChatId,
                    $appointment->id,
                    AppointmentNotice::ConfirmedForClient,
                );
            }

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

        // Клиент, отменивший запись сам в боте, уже получил ответ в том же
        // сообщении — второе уведомление было бы шумом от собственного действия.
        if ($appointment->client !== null && ! $appointment->cancelledByClient) {
            NotifyAppointmentClientOfCancellation::dispatch($appointment->id);
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
