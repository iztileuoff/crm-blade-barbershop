<?php

use App\Enums\AppointmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill clients.last_visit_at from each client's most recent completed
     * appointment. Historically the field was never written, so SMS-retention
     * matched nobody. Only fills rows that are still NULL — never overwrites a
     * value already set by the observer. DB-agnostic (MySQL prod / SQLite tests).
     */
    public function up(): void
    {
        if (! Schema::hasTable('clients') || ! Schema::hasTable('appointments')) {
            return;
        }

        DB::table('clients')
            ->whereNull('last_visit_at')
            ->orderBy('id')
            ->chunkById(200, function ($clients) {
                foreach ($clients as $client) {
                    $lastVisit = DB::table('appointments')
                        ->where('client_id', $client->id)
                        ->where('status', AppointmentStatus::Completed->value)
                        ->max('starts_at');

                    if ($lastVisit !== null) {
                        DB::table('clients')
                            ->where('id', $client->id)
                            ->update(['last_visit_at' => $lastVisit]);
                    }
                }
            });
    }

    /**
     * Not reversible: we cannot tell backfilled values apart from ones the
     * application set legitimately, so we leave the data in place.
     */
    public function down(): void
    {
        //
    }
};
