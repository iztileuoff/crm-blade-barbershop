<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barbers', function (Blueprint $table) {
            $table->foreignId('specialization_id')
                ->nullable()
                ->after('name')
                ->constrained('specializations')
                ->nullOnDelete();

            $table->dropColumn(['specialization', 'photo_path']);
        });
    }

    public function down(): void
    {
        Schema::table('barbers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('specialization_id');
            $table->string('specialization')->nullable()->after('name');
            $table->string('photo_path')->nullable()->after('specialization');
        });
    }
};
