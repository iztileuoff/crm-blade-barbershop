<?php

namespace App\Telegram\Handlers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Support\BarberEarnings;
use App\Telegram\AppointmentFormatter;
use App\Telegram\TelegramLinker;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;

class BarberMenuHandler
{
    public function __construct(private readonly TelegramLinker $linker) {}

    public function today(Nutgram $bot): void
    {
        $this->schedule($bot, Carbon::today(), 'Расписание на сегодня');
    }

    public function tomorrow(Nutgram $bot): void
    {
        $this->schedule($bot, Carbon::tomorrow(), 'Расписание на завтра');
    }

    public function earnings(Nutgram $bot): void
    {
        $barber = $this->barber($bot);

        if ($barber === null) {
            return;
        }

        $percent = $barber->salary_percent ?? BarberEarnings::DEFAULT_SALARY_PERCENT;

        // Формула зарплаты живёт в App\Support\BarberEarnings — та же точка
        // входа, что и у страницы «Мой заработок» в CRM, иначе экраны
        // разъезжаются в цифрах. Периоды берутся целиком: визит, закрытый
        // заранее на конец недели, сразу виден в «За неделю», и «Сегодня»
        // гарантированно входит в оба периода.
        $now = Carbon::now();

        $text = sprintf(
            "💰 <b>Ваш заработок</b> (доля %d%%)\n\nСегодня: <b>%s</b>\nЗа неделю: <b>%s</b>\nЗа месяц: <b>%s</b>",
            $percent,
            $this->money(BarberEarnings::periodShare($barber, $now->copy()->startOfDay(), $now->copy()->endOfDay())),
            $this->money(BarberEarnings::periodShare($barber, $now->copy()->startOfWeek(), $now->copy()->endOfWeek())),
            $this->money(BarberEarnings::periodShare($barber, $now->copy()->startOfMonth(), $now->copy()->endOfMonth())),
        );

        $bot->sendMessage($text, parse_mode: 'HTML');
    }

    private function schedule(Nutgram $bot, Carbon $day, string $title): void
    {
        $barber = $this->barber($bot);

        if ($barber === null) {
            return;
        }

        $appointments = Appointment::query()
            ->with(['client', 'services'])
            ->where('barber_id', $barber->id)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->forDay($day)
            ->orderBy('starts_at')
            ->get();

        if ($appointments->isEmpty()) {
            $bot->sendMessage("📭 На {$day->translatedFormat('d MMMM')} записей нет.");

            return;
        }

        $lines = $appointments->map(fn (Appointment $a) => AppointmentFormatter::barberLine($a));

        $bot->sendMessage(
            "✂️ <b>{$title}</b>\n\n".$lines->implode("\n"),
            parse_mode: 'HTML',
        );
    }

    private function barber(Nutgram $bot): ?Barber
    {
        $chatId = $bot->chatId();
        $user = $chatId !== null ? $this->linker->findBarberUserByChat($chatId) : null;
        $barber = $user?->barber;

        if ($barber === null) {
            $bot->sendMessage('Сначала привяжите профиль командой /start.');
        }

        return $barber;
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, '.', ' ').' сум';
    }
}
