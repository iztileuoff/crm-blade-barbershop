<?php

namespace App\Telegram\Handlers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\DebtPayment;
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

        // Формула зарплаты живёт на моделях — иначе бот и дашборд разъезжаются.
        // Доля с погашения начисляется в день платежа, поэтому периоды считаются
        // ровно так же, как в дашборде, и «Сегодня» всегда входит в «За месяц».
        $share = function (Carbon $from, Carbon $to) use ($barber, $percent): int {
            $fromVisits = Appointment::query()
                ->where('barber_id', $barber->id)
                ->where('status', AppointmentStatus::Completed->value)
                ->whereBetween('starts_at', [$from, $to])
                ->get()
                ->sum(fn (Appointment $a) => $a->salaryShare($percent));

            $fromDebts = DebtPayment::query()
                ->betweenDates($from, $to)
                ->whereHasMorph('payable', [Appointment::class], fn ($q) => $q->where('barber_id', $barber->id))
                ->with('payable')
                ->get()
                ->sum(fn (DebtPayment $p) => $p->salaryShare);

            return (int) ($fromVisits + $fromDebts);
        };

        // Периоды берутся целиком: визит, закрытый заранее на конец недели, сразу
        // виден в «За неделю», и «Сегодня» гарантированно входит в оба периода.
        $now = Carbon::now();

        $text = sprintf(
            "💰 <b>Ваш заработок</b> (доля %d%%)\n\nСегодня: <b>%s</b>\nЗа неделю: <b>%s</b>\nЗа месяц: <b>%s</b>",
            $percent,
            $this->money($share($now->copy()->startOfDay(), $now->copy()->endOfDay())),
            $this->money($share($now->copy()->startOfWeek(), $now->copy()->endOfWeek())),
            $this->money($share($now->copy()->startOfMonth(), $now->copy()->endOfMonth())),
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
