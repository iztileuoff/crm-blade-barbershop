<?php

namespace App\Telegram\Handlers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Telegram\AppointmentFormatter;
use App\Telegram\TelegramLinker;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;

class ClientMenuHandler
{
    public function __construct(private readonly TelegramLinker $linker) {}

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
            $bot->sendMessage('📭 У вас нет предстоящих записей.');

            return;
        }

        $lines = $appointments->map(fn (Appointment $a) => AppointmentFormatter::clientLine($a));

        $bot->sendMessage("📅 <b>Ваши записи</b>\n\n".$lines->implode("\n"), parse_mode: 'HTML');
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
            $bot->sendMessage('📭 История визитов пока пуста.');

            return;
        }

        $lines = $appointments->map(fn (Appointment $a) => AppointmentFormatter::clientLine($a));

        $bot->sendMessage("🕓 <b>Последние визиты</b>\n\n".$lines->implode("\n"), parse_mode: 'HTML');
    }

    public function debt(Nutgram $bot): void
    {
        $client = $this->client($bot);

        if ($client === null) {
            return;
        }

        $total = (int) Appointment::query()
            ->where('client_id', $client->id)
            ->where('debt_amount', '>', 0)
            ->sum('debt_amount');

        if ($total === 0) {
            $bot->sendMessage('✅ За вами нет задолженности.');

            return;
        }

        $bot->sendMessage(
            sprintf('💳 <b>Ваш долг:</b> %s', number_format($total, 0, '.', ' ').' сум'),
            parse_mode: 'HTML',
        );
    }

    private function client(Nutgram $bot): ?Client
    {
        $chatId = $bot->chatId();
        $client = $chatId !== null ? $this->linker->findClientByChat($chatId) : null;

        if ($client === null) {
            $bot->sendMessage('Сначала привяжите профиль командой /start.');
        }

        return $client;
    }
}
