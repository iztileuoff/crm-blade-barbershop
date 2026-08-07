<?php

namespace App\Telegram\Handlers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Order;
use App\Telegram\AppointmentFormatter;
use App\Telegram\Keyboards;
use App\Telegram\TelegramLinker;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;

class ClientMenuHandler
{
    public function __construct(private readonly TelegramLinker $linker) {}

    /**
     * Каждая предстоящая запись — отдельным сообщением со своей инлайн-кнопкой
     * «Отменить» (#76): у Telegram нет кнопки «на одну строку» внутри общего
     * списка, поэтому список превратился в заголовок + по сообщению на запись.
     */
    public function appointments(Nutgram $bot): void
    {
        $client = $this->client($bot);

        if ($client === null) {
            return;
        }

        $appointments = Appointment::query()
            ->with(['barber', 'services'])
            ->where('client_id', $client->id)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('starts_at', '>=', Carbon::now())
            ->orderBy('starts_at')
            ->get();

        if ($appointments->isEmpty()) {
            $bot->sendMessage(__('telegram.no_upcoming_appointments'));

            return;
        }

        $bot->sendMessage(__('telegram.your_appointments_title'), parse_mode: 'HTML');

        foreach ($appointments as $appointment) {
            $canCancel = $appointment->status !== AppointmentStatus::Completed
                && $appointment->starts_at->isFuture();

            $bot->sendMessage(
                AppointmentFormatter::clientLine($appointment),
                parse_mode: 'HTML',
                reply_markup: $canCancel ? Keyboards::cancelAppointment($appointment->id) : null,
            );
        }
    }

    public function history(Nutgram $bot): void
    {
        $client = $this->client($bot);

        if ($client === null) {
            return;
        }

        $appointments = Appointment::query()
            ->with(['barber', 'services'])
            ->where('client_id', $client->id)
            ->where('starts_at', '<', Carbon::now())
            ->orderByDesc('starts_at')
            ->limit(10)
            ->get();

        if ($appointments->isEmpty()) {
            $bot->sendMessage(__('telegram.no_history'));

            return;
        }

        $lines = $appointments->map(fn (Appointment $a) => AppointmentFormatter::clientLine($a));

        $bot->sendMessage(__('telegram.history_title')."\n\n".$lines->implode("\n"), parse_mode: 'HTML');
    }

    /**
     * Долг клиента — непогашенный остаток, а не выданная сумма: `debt_amount`
     * при погашении не обнуляется, погашения лежат в `debt_payments`. Считаем
     * теми же скоупами, что и страница долгов, и по обеим кассовым операциям —
     * иначе бот требует уже принесённые деньги и не видит долг за товар.
     */
    public function debt(Nutgram $bot): void
    {
        $client = $this->client($bot);

        if ($client === null) {
            return;
        }

        $total = Appointment::query()
            ->where('client_id', $client->id)
            ->withOutstandingDebt()
            ->sumOutstandingDebt()
            + Order::query()
                ->where('client_id', $client->id)
                ->withOutstandingDebt()
                ->sumOutstandingDebt();

        if ($total === 0) {
            $bot->sendMessage(__('telegram.no_debt'));

            return;
        }

        $bot->sendMessage(
            __('telegram.debt_amount', ['amount' => number_format($total, 0, '.', ' ').' '.__('common.currency')]),
            parse_mode: 'HTML',
        );
    }

    private function client(Nutgram $bot): ?Client
    {
        $chatId = $bot->chatId();
        $client = $chatId !== null ? $this->linker->findClientByChat($chatId) : null;

        if ($client === null) {
            $bot->sendMessage(__('telegram.not_linked'));
        }

        return $client;
    }
}
