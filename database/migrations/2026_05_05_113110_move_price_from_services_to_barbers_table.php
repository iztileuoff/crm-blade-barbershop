<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::table('barbers', function (Blueprint $table) {
            $table->unsignedBigInteger('price')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('barbers', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unsignedBigInteger('price')->nullable();
        });
    }
};
