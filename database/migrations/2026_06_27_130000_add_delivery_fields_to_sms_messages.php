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
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('eskiz_message_id')->nullable()->after('context')->index();
            $table->unsignedTinyInteger('parts')->default(1)->after('eskiz_message_id');
            $table->string('delivery_status')->nullable()->after('parts'); // delivered | undelivered | rejected | waiting
            $table->timestamp('status_checked_at')->nullable()->after('delivery_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn(['eskiz_message_id', 'parts', 'delivery_status', 'status_checked_at']);
        });
    }
};
