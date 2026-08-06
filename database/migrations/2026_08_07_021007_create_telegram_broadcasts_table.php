<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Один запуск рассылки — по образцу sms_messages, но на уровне пачки,
     * а не отдельного получателя: получатель Telegram-рассылки не хранится
     * поштучно, а только агрегированными счётчиками, которые обновляет job.
     */
    public function up(): void
    {
        if (Schema::hasTable('telegram_broadcasts')) {
            return;
        }

        Schema::create('telegram_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience'); // clients | barbers | all
            $table->text('message');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            // null, пока job ещё не отработал до конца — отличает «в процессе» от «0 успехов».
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_broadcasts');
    }
};
