<?php

use App\Models\Service;
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

        // Convert each existing single-language name into the {ru,uz,kaa} JSON
        // shape. A name known to the catalogue (in any language) is mapped to its
        // full translation set; an unknown name is mirrored across every locale so
        // nothing silently disappears. Durations and other columns are untouched.
        DB::table('services')->orderBy('id')->each(function (object $service) {
            $name = (string) $service->name;

            if (is_array(json_decode($name, true))) {
                return; // already migrated
            }

            $translations = Service::matchCatalogue($name) ?? [
                'ru' => $name,
                'uz' => $name,
                'kaa' => $name,
            ];

            DB::table('services')->where('id', $service->id)->update([
                'name' => Service::encodeTranslations($translations),
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
