<?php

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runRepairMigration(): void
{
    $migration = require database_path('migrations/2026_08_06_190500_repair_broken_cash_register_rows.php');
    $migration->up();
}

it('clamps a debt that exceeds the price', function () {
    $id = DB::table('appointments')->insertGetId([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => Barber::factory()->create()->id,
        'starts_at' => '2026-07-19 12:00:00',
        'ends_at' => '2026-07-19 13:00:00',
        'status' => 'completed',
        'price' => 60000,
        'payment_type' => 'cash',
        'debt_amount' => 150000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runRepairMigration();

    expect((int) Appointment::find($id)->debt_amount)->toBe(60000);
});

it('repairs every mixed-split shape exactly as reported in the issue', function () {
    // [цена, нал, карта, долг] => [ожидаемый нал, ожидаемая карта]
    $cases = [
        'empty split (#67)' => [[100000, null, null, null], [100000, 0]],
        'double split (#673)' => [[60000, 60000, 60000, null], [60000, 0]],
        'short split (#525)' => [[120000, 60000, 40000, null], [60000, 60000]],
    ];

    $ids = [];

    foreach ($cases as $label => [[$price, $cash, $card, $debt], $_]) {
        $ids[$label] = DB::table('orders')->insertGetId([
            'total_price' => $price,
            'payment_type' => 'both',
            'cash_amount' => $cash,
            'card_amount' => $card,
            'debt_amount' => $debt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    runRepairMigration();

    foreach ($cases as $label => [$input, [$expectedCash, $expectedCard]]) {
        $order = Order::find($ids[$label]);

        expect((int) ($order->cash_amount ?? 0))->toBe($expectedCash, $label)
            ->and((int) ($order->card_amount ?? 0))->toBe($expectedCard, $label)
            // и главное: разбивка сходится с полученной суммой
            ->and($order->cashReceived + $order->cardReceived)->toBe($order->receivedAmount, $label);
    }
});

/**
 * Раньше эти две строки чинились «в ноль»: ожидаемый приход при цене 0 равен
 * нулю, значит и нал с картой обнулялись. На проде это записи #222 и #231 —
 * завершённые мужские стрижки у мастера с базовой ценой 60 000, где записаны
 * 50 000 и 120 000. Ошибочна там цена, а не деньги: сумма — единственный след
 * того, что реально пришло в кассу, и стирать её значит уменьшить май на
 * 170 000. Строка остаётся кассиру, как и визит без цены вовсе.
 */
it('never erases money recorded against a zero price', function () {
    $cases = [
        'zero price, split (#222)' => [0, 30000, 20000, null],
        'zero price, card only (#231)' => [0, null, 120000, null],
        'everything on credit, money still recorded' => [60000, 40000, null, 60000],
    ];

    $ids = [];

    foreach ($cases as $label => [$price, $cash, $card, $debt]) {
        $ids[$label] = DB::table('orders')->insertGetId([
            'total_price' => $price,
            'payment_type' => 'both',
            'cash_amount' => $cash,
            'card_amount' => $card,
            'debt_amount' => $debt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    runRepairMigration();

    foreach ($cases as $label => [$price, $cash, $card, $debt]) {
        $row = DB::table('orders')->find($ids[$label]);

        expect($row->cash_amount === null ? null : (int) $row->cash_amount)->toBe($cash, $label)
            ->and($row->card_amount === null ? null : (int) $row->card_amount)->toBe($card, $label);
    }
});

it('never touches an appointment without a price', function () {
    // (int) NULL === 0 однажды заставило починку стереть реально принятые деньги.
    $id = DB::table('appointments')->insertGetId([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => Barber::factory()->create()->id,
        'starts_at' => '2026-07-01 12:00:00',
        'ends_at' => '2026-07-01 13:00:00',
        'status' => 'completed',
        'price' => null,
        'payment_type' => 'both',
        'cash_amount' => 50000,
        'card_amount' => null,
        'debt_amount' => 150000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runRepairMigration();

    $row = DB::table('appointments')->find($id);

    expect((int) $row->cash_amount)->toBe(50000)
        ->and($row->card_amount)->toBeNull()
        ->and((int) $row->debt_amount)->toBe(150000);
});

it('leaves a correct mixed split untouched', function () {
    $id = DB::table('orders')->insertGetId([
        'total_price' => 100000,
        'payment_type' => 'both',
        'cash_amount' => 60000,
        'card_amount' => 20000,
        'debt_amount' => 20000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runRepairMigration();

    $order = Order::find($id);

    expect((int) $order->cash_amount)->toBe(60000)
        ->and((int) $order->card_amount)->toBe(20000)
        ->and((int) $order->debt_amount)->toBe(20000);
});

it('is idempotent', function () {
    $id = DB::table('orders')->insertGetId([
        'total_price' => 60000,
        'payment_type' => 'both',
        'cash_amount' => 60000,
        'card_amount' => 60000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runRepairMigration();
    $afterFirst = Order::find($id)->only(['cash_amount', 'card_amount', 'debt_amount']);

    runRepairMigration();

    expect(Order::find($id)->only(['cash_amount', 'card_amount', 'debt_amount']))->toBe($afterFirst);
});

it('only rewrites appointment 1271 when every guard field matches', function () {
    // Чужая база: тот же id, но другой мастер и цена — трогать нельзя.
    DB::table('appointments')->insert([
        'id' => 1271,
        'client_id' => Client::factory()->create()->id,
        'barber_id' => Barber::factory()->create()->id,
        'starts_at' => '2026-08-06 09:00:00',
        'ends_at' => '2026-08-06 10:00:00',
        'status' => 'completed',
        'price' => 50000,
        'salary_percent' => 100,
        'payment_type' => 'cash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runRepairMigration();

    expect((int) Appointment::find(1271)->salary_percent)->toBe(100);
});
