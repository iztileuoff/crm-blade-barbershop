<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Разовая корректировка зафиксированного процента для уже завершённых
     * записей за июнь 2026: до 16 июня у всех мастеров (кроме топ-мастера,
     * barber_id = 1) было 50%, с 16 июня — 60%. Топ-мастер (100%) не менялся.
     *
     * Время хранится в Asia/Tashkent (app timezone), поэтому граница берётся
     * как локальная стена времени.
     */
    public function up(): void
    {
        $cutoff = '2026-06-16 00:00:00';
        $topMasterId = 1;

        DB::table('appointments')
            ->where('status', 'completed')
            ->where('barber_id', '<>', $topMasterId)
            ->where('starts_at', '<', $cutoff)
            ->update(['salary_percent' => 50]);

        DB::table('appointments')
            ->where('status', 'completed')
            ->where('barber_id', '<>', $topMasterId)
            ->where('starts_at', '>=', $cutoff)
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
