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
     * Сколько ближайших записей показываем одним ответом. Каждая уходит
     * отдельным сообщением, а Telegram держит около одного сообщения в секунду
     * на чат: без потолка клиент с двумя десятками записей упирался бы в
     * флуд-лимит, а вебхук — в таймаут, после которого Telegram переотправляет
     * апдейт и вся серия начинается заново.
     */
    private const UPCOMING_LIMIT = 10;

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

        $upcoming = Appointment::query()
            ->where('client_id', $client->id)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('starts_at', '>=', Carbon::now());

        $total = (clone $upcoming)->count();

        if ($total === 0) {
            $bot->sendMessage(__('telegram.no_upcoming_appointments'));

            return;
        }

        $appointments = $upcoming
            ->with(['barber', 'services'])
            ->orderBy('starts_at')
            ->limit(self::UPCOMING_LIMIT)
            ->get();

        // Про обрезку говорим вслух: «ваши записи» без хвоста читалось бы как
        // «это всё, что у вас есть».
        $title = $total > $appointments->count()
            ? __('telegram.your_appointments_title')."\n".__('telegram.upcoming_truncated', [
                'shown' => $appointments->count(),
                'total' => $total,
            ])
            : __('telegram.your_appointments_title');

        $bot->sendMessage($title, parse_mode: 'HTML');

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
