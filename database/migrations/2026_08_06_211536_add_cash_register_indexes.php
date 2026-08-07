<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Индексы под запросы кассы.
     *
     * `orders.created_at` — по нему фильтруют дашборд (день, месяц, график) и
     * список продаж, то есть три полных скана за один рендер дашборда.
     * `debt_amount` — по нему отбирается список должников в обеих таблицах.
     */
    /**
     * Индексы под запросы кассы, по одному в своём Schema::table().
     *
     * Каждый под hasIndex-guard: без него повторный прогон (например, если
     * деплой упал между миграциями и его перезапустили) валится на MySQL с
     * «Duplicate key name» и оставляет пачку недоприменённой.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const INDEXES = [
        ['orders', 'created_at'],
        ['orders', 'debt_amount'],
        ['appointments', 'debt_amount'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $column]) {
            if (Schema::hasIndex($table, "{$table}_{$column}_index")) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->index($column);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, $column]) {
            if (! Schema::hasIndex($table, "{$table}_{$column}_index")) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropIndex([$column]);
            });
        }
    }
};
