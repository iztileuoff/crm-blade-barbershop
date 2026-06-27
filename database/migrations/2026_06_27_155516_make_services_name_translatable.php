<?php

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
        if (! Schema::hasTable('services')) {
            return;
        }

        // Widen the column so it comfortably holds the {ru,uz,kaa} JSON payload.
        Schema::table('services', function (Blueprint $table) {
            $table->text('name')->change();
        });

        // Convert any existing single-language name into the JSON shape, mirroring
        // the legacy value across every locale so no name silently disappears.
        DB::table('services')->orderBy('id')->each(function (object $service) {
            if (is_array(json_decode((string) $service->name, true))) {
                return; // already migrated
            }

            DB::table('services')->where('id', $service->id)->update([
                'name' => json_encode([
                    'ru' => $service->name,
                    'uz' => $service->name,
                    'kaa' => $service->name,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        // Collapse the JSON translations back to a single Russian-first string.
        DB::table('services')->orderBy('id')->each(function (object $service) {
            $decoded = json_decode((string) $service->name, true);

            if (! is_array($decoded)) {
                return;
            }

            $name = $decoded['ru'] ?? '';
            if ($name === '') {
                $name = (string) (reset($decoded) ?: '');
            }

            DB::table('services')->where('id', $service->id)->update([
                'name' => $name,
            ]);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('name')->change();
        });
    }
};
