<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Разовая починка операций, испорченных до появления перекрёстной валидации
     * денежных полей (issues #18 и #20).
     *
     * Все правки условные, а не по жёстким id (кроме одной адресной), поэтому
     * миграция идемпотентна и безопасна на любой БД: повторный прогон уже ничего
     * не находит.
     */
    public function up(): void
    {
        $this->clampDebtToPrice('appointments', 'price');
        $this->clampDebtToPrice('orders', 'total_price');

        $this->repairMixedSplit('appointments', 'price');
        $this->repairMixedSplit('orders', 'total_price');

        $this->fixSwappedSalaryPercent();
    }

    /**
     * Долг больше стоимости — приход обнулялся, а «выдано в долг» завышалось.
     * Обрезаем долг до стоимости операции.
     */
    private function clampDebtToPrice(string $table, string $priceColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'debt_amount')) {
            return;
        }

        DB::table($table)
            ->whereNotNull('debt_amount')
            ->whereColumn('debt_amount', '>', $priceColumn)
            ->update(['debt_amount' => DB::raw($priceColumn)]);
    }

    /**
     * Смешанная оплата, где «наличные + карта» не сходятся с (цена − долг).
     *
     * Правило починки:
     *  - разбивка пустая        → всё в наличные;
     *  - наличные не больше     → карта = остаток после наличных;
     *  - наличные больше суммы  → всё в наличные, карта обнуляется.
     */
    private function repairMixedSplit(string $table, string $priceColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'cash_amount')) {
            return;
        }

        // appointments.price — nullable. Без этого условия (int) NULL даёт 0,
        // «полученное» считается нулём, и починка стирает реально принятые
        // деньги. Строки без цены не наша забота — их правит кассир вручную.
        $rows = DB::table($table)
            ->where('payment_type', 'both')
            ->whereNotNull($priceColumn)
            ->get(['id', $priceColumn, 'cash_amount', 'card_amount', 'debt_amount']);

        foreach ($rows as $row) {
            $expected = max(0, (int) $row->{$priceColumn} - (int) ($row->debt_amount ?? 0));
            $cash = (int) ($row->cash_amount ?? 0);
            $card = (int) ($row->card_amount ?? 0);

            if ($cash + $card === $expected) {
                continue;
            }

            // Получить нечего, а деньги записаны — это не кривая разбивка, а
            // незаполненная цена: завершённая стрижка за 0 не бывает, ошибочно
            // здесь именно поле цены, а не приход. Записанные деньги — самая
            // достоверная часть строки, и стирать её нельзя: на проде это две
            // операции мая на 170 000, единственный след которых — эти суммы.
            // Тот же случай, что и price IS NULL выше, только цена не NULL, а 0
            // (сюда же попадает визит целиком в долг с приходом). Строку правит
            // кассир вручную — миграция её не трогает.
            if ($expected === 0) {
                continue;
            }

            $newCash = ($cash <= 0 && $card <= 0) || $cash > $expected ? $expected : $cash;
            $newCard = $expected - $newCash;

            DB::table($table)->where('id', $row->id)->update([
                'cash_amount' => $newCash ?: null,
                'card_amount' => $newCard ?: null,
            ]);
        }
    }

    /**
     * Запись #1271: завершённую запись перевесили на другого мастера, а
     * зафиксированный процент остался от прежнего (100 % вместо 60 %).
     *
     * Адресная правка — «процент записи не равен текущему проценту мастера»
     * условием быть не может: расхождение там штатное (ставка меняется во
     * времени, снимок на то и снимок). Поэтому сверяем все опорные поля, и на
     * любой другой БД миграция просто ничего не найдёт.
     */
    private function fixSwappedSalaryPercent(): void
    {
        if (! Schema::hasTable('appointments') || ! Schema::hasColumn('appointments', 'salary_percent')) {
            return;
        }

        DB::table('appointments')
            ->where('id', 1271)
            ->where('barber_id', 4)
            ->where('price', 300000)
            ->where('salary_percent', 100)
            ->where('status', 'completed')
            ->update(['salary_percent' => 60]);
    }

    /**
     * Разовая корректировка данных — откат не предусмотрен.
     */
    public function down(): void
    {
        //
    }
};
