<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Погашение долга — самостоятельная операция со своей датой и способом оплаты.
     *
     * Раньше погашение просто уменьшало debt_amount у исходной записи/продажи,
     * из-за чего деньги попадали в кассу того дня, когда была оказана услуга,
     * а не того, когда их реально принесли. Теперь debt_amount — это долг,
     * ВЫДАННЫЙ в момент операции (не меняется), а остаток считается как
     * «выдано минус погашено».
     */
    public function up(): void
    {
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable');
            $table->unsignedInteger('amount');
            $table->string('payment_type')->default('cash');
            // datetime, а не timestamp: MySQL при explicit_defaults_for_timestamp=OFF
            // вешает на первую TIMESTAMP-колонку ON UPDATE CURRENT_TIMESTAMP, и любое
            // обновление строки переносило бы деньги в другой день. Заодно нет потолка 2038.
            $table->dateTime('paid_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
