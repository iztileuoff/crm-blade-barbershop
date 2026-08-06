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
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('debt_amount');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->index('debt_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['debt_amount']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['debt_amount']);
        });
    }
};
