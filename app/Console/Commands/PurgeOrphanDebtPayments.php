<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\DebtPayment;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:purge-orphan-debt-payments {--dry-run : Показать, что будет удалено, без удаления}')]
#[Description('Удаляет погашения долга, у которых больше нет ни записи, ни продажи')]
class PurgeOrphanDebtPayments extends Command
{
    /**
     * Удаление клиента или мастера уносит записи каскадом на уровне БД —
     * событий Eloquent при этом не возникает, и погашения по ним остаются
     * сиротами. Дашборд их не считает (там `whereHasMorph`), но в таблице они
     * копятся и пересканируются при каждом рендере.
     */
    public function handle(): int
    {
        $query = DebtPayment::query()
            ->whereDoesntHaveMorph('payable', [Appointment::class, Order::class]);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Осиротевших погашений нет.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Пробный запуск: под удаление попадает погашений — {$count}.");

            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Удалено осиротевших погашений: {$count}.");

        return self::SUCCESS;
    }
}
