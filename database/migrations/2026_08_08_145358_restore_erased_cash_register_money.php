<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Возврат денег, стёртых починкой кассы до её исправления (a1ea715).
     *
     * Прод успел прогнать `2026_08_06_190500_repair_broken_cash_register_rows`
     * в тот день, когда у неё ещё не было guard'а `$expected === 0`: при цене 0
     * ожидаемый приход равен нулю, поэтому нал и карта обнулялись, а `?: null`
     * превращал нули в NULL. Запись о починке уже лежит в `migrations` (batch
     * 14), `down()` у неё пустой — сама она больше не отработает, и вернуть
     * суммы может только отдельная миграция.
     *
     * Это записи #222 и #231 — завершённые мужские стрижки у мастера #4 с
     * базовой ценой 60 000, где цену не заполнили (`price` и
     * `appointment_service.amount` равны нулю), а деньги записали: 30 000 + 20 000
     * и 120 000 картой, ровно два прайса подряд. Суммы в колонках — единственный
     * сохранившийся след того, что реально пришло в мае.
     *
     * Цену миграция не трогает: на кассу и на оклад влияет именно она (касса
     * считается от price − debt), а сколько там было на самом деле, знает только
     * кассир — 120 000 при базовых 60 000 это не одна стрижка. Возвращаем ровно
     * то, что стёрли, и оставляем строку кассиру.
     *
     * @var list<array{id: int, client_id: int, cash_amount: int|null, card_amount: int|null}>
     */
    private const ERASED_ROWS = [
        ['id' => 222, 'client_id' => 187, 'cash_amount' => 30000, 'card_amount' => 20000],
        ['id' => 231, 'client_id' => 195, 'cash_amount' => null, 'card_amount' => 120000],
    ];

    /**
     * Правка адресная, поэтому сверяем все опорные поля — на чужой БД с теми же
     * id миграция просто ничего не найдёт. Условие «деньги сейчас NULL» делает
     * её идемпотентной: повторный прогон и уже поправленную вручную строку не
     * перезапишет.
     */
    public function up(): void
    {
        if (! $this->tableIsReady()) {
            return;
        }

        foreach (self::ERASED_ROWS as $row) {
            $this->erasedRowQuery($row['id'], $row['client_id'])
                ->whereNull('cash_amount')
                ->whereNull('card_amount')
                ->update([
                    'cash_amount' => $row['cash_amount'],
                    'card_amount' => $row['card_amount'],
                ]);
        }
    }

    /**
     * Откат возвращает строки в то состояние, в котором их оставила сломанная
     * починка, — и только если с тех пор их никто не правил.
     */
    public function down(): void
    {
        if (! $this->tableIsReady()) {
            return;
        }

        foreach (self::ERASED_ROWS as $row) {
            $this->erasedRowQuery($row['id'], $row['client_id'])
                ->where('cash_amount', $row['cash_amount'])
                ->where('card_amount', $row['card_amount'])
                ->update([
                    'cash_amount' => null,
                    'card_amount' => null,
                ]);
        }
    }

    /**
     * Опорные поля записи: всё, что починка не меняла и что отличает эти две
     * строки от любой чужой с тем же id.
     */
    private function erasedRowQuery(int $id, int $clientId): Builder
    {
        return DB::table('appointments')
            ->where('id', $id)
            ->where('client_id', $clientId)
            ->where('barber_id', 4)
            ->where('price', 0)
            ->where('payment_type', 'both')
            ->where('status', 'completed');
    }

    /**
     * На чужой или недостроенной БД таблицы может не быть вовсе.
     */
    private function tableIsReady(): bool
    {
        return Schema::hasTable('appointments')
            && Schema::hasColumn('appointments', 'cash_amount');
    }
};
