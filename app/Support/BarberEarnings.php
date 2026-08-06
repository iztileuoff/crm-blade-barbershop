<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\DebtPayment;
use Illuminate\Support\Carbon;

/**
 * Заработок мастера за период — единственное место, где считается сумма для
 * отчётных экранов (Telegram-бот, страница «Мой заработок» в CRM). Обе точки
 * входа обязаны звать этот метод, а не пересчитывать формулу на месте —
 * иначе экраны разъезжаются в цифрах, как уже было с зарплатой (#29, #44).
 *
 * Саму формулу долей эта величина не меняет — она лишь суммирует то, что
 * уже посчитано на моделях:
 *
 * @see Appointment::salaryShare() доля с денег, полученных при визите
 * @see DebtPayment::$salaryShare доля с погашения долга, в день платежа
 */
class BarberEarnings
{
    /**
     * Тот же дефолт, что и у колонки barbers.salary_percent в БД.
     */
    public const DEFAULT_SALARY_PERCENT = 50;

    /**
     * Доля мастера за период: деньги, полученные по визитам, завершённым в
     * периоде, плюс доли с погашений долгов, принятых в периоде.
     *
     * Погашение — операция дня платежа, а не дня визита, поэтому суммы по
     * дням складываются в месяц, а закрытые периоды не пересчитываются
     * задним числом (см. [[salary-accrues-from-received-money]]).
     */
    public static function periodShare(Barber $barber, Carbon $from, Carbon $to): int
    {
        $percent = $barber->salary_percent ?? self::DEFAULT_SALARY_PERCENT;

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
    }
}
