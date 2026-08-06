<?php

namespace App\Telegram\Handlers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Telegram\AppointmentFormatter;
use App\Telegram\TelegramLinker;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;

class BarberMenuHandler
{
    /**
     * Тот же дефолт, что и у колонки barbers.salary_percent в БД.
     */
    private const DEFAULT_SALARY_PERCENT = 50;

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

        $percent = $barber->salary_percent ?? self::DEFAULT_SALARY_PERCENT;

        // Формула зарплаты живёт на модели — иначе бот и дашборд разъезжаются.
        // Погашения внутри периода добираются тем же скоупом, что и на дашборде.
        $share = function (Carbon $from, Carbon $to) use ($barber, $percent): int {
            return (int) Appointment::query()
                ->where('barber_id', $barber->id)
                ->where('status', AppointmentStatus::Completed->value)
                ->withDebtCollectedBetween($from, $to)
                ->whereBetween('starts_at', [$from, $to])
                ->get()
                ->sum(fn (Appointment $a) => $a->salaryShare($percent));
        };

        // Правая граница у всех трёх периодов одна — конец сегодняшнего дня. Иначе
        // запись, завершённая заранее на вечер, попадала бы в «Сегодня», но не в
        // «За неделю»/«За месяц», и «Сегодня» оказывалось больше «За месяц».
        $now = Carbon::now();
        $until = $now->copy()->endOfDay();

        $text = sprintf(
            "💰 <b>Ваш заработок</b> (доля %d%%)\n\nСегодня: <b>%s</b>\nЗа неделю: <b>%s</b>\nЗа месяц: <b>%s</b>",
            $percent,
            $this->money($share($now->copy()->startOfDay(), $until)),
            $this->money($share($now->copy()->startOfWeek(), $until)),
            $this->money($share($now->copy()->startOfMonth(), $until)),
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
