<?php

use App\Enums\AppointmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedTinyInteger('salary_percent')->nullable()->after('price');
        });

        // Снимок процента для уже завершённых записей: используем текущий процент
        // мастера. Дальше процент фиксируется в момент завершения каждой записи.
        DB::table('appointments')
            ->where('status', AppointmentStatus::Completed->value)
            ->update([
                'salary_percent' => DB::raw('(SELECT salary_percent FROM barbers WHERE barbers.id = appointments.barber_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('salary_percent');
        });
    }
};
