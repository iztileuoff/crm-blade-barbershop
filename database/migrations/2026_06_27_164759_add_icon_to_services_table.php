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

        if (! Schema::hasColumn('services', 'icon')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('icon')->nullable()->after('name');
            });
        }

        // Give existing base-catalogue rows their canonical icon so production
        // services are not all rendered with the fallback. Unknown names keep a
        // null icon and fall back to the default at render time. Idempotent.
        $icons = Service::catalogueIcons();

        DB::table('services')->orderBy('id')->each(function (object $service) use ($icons) {
            if (($service->icon ?? null) !== null && $service->icon !== '') {
                return;
            }

            // The name column holds a {ru,uz,kaa} JSON map by this point; match
            // the catalogue on each translation so a row stored in any language
            // resolves to its canonical icon.
            $entry = null;
            foreach (Service::decodeTranslations((string) $service->name) as $value) {
                if (is_string($value) && $value !== '' && ($entry = Service::matchCatalogue($value)) !== null) {
                    break;
                }
            }

            $icon = $entry !== null ? ($icons[$entry['ru']] ?? null) : null;

            if ($icon !== null) {
                DB::table('services')->where('id', $service->id)->update(['icon' => $icon]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'icon')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
