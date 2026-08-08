<?php

use App\Models\Barber;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runRestoreMigration(): void
{
    $migration = require database_path('migrations/2026_08_08_145358_restore_erased_cash_register_money.php');
    $migration->up();
}

function rollBackRestoreMigration(): void
{
    $migration = require database_path('migrations/2026_08_08_145358_restore_erased_cash_register_money.php');
    $migration->down();
}

/**
 * Строка в том виде, в каком её оставила сломанная починка: цена нулевая,
 * деньги стёрты в NULL.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertErasedAppointment(int $id, int $clientId, array $overrides = []): void
{
    $client = Client::factory()->create(['id' => $clientId]);

    if (! Barber::find(4)) {
        Barber::factory()->create(['id' => 4]);
    }

    DB::table('appointments')->insert(array_merge([
        'id' => $id,
        'client_id' => $client->id,
        'barber_id' => 4,
        'starts_at' => '2026-05-24 09:00:00',
        'ends_at' => '2026-05-24 10:00:00',
        'status' => 'completed',
        'price' => 0,
        'salary_percent' => 50,
        'payment_type' => 'both',
        'cash_amount' => null,
        'card_amount' => null,
        'debt_amount' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('gives back the money the broken repair erased', function () {
    insertErasedAppointment(222, 187);
    insertErasedAppointment(231, 195);

    runRestoreMigration();

    $first = DB::table('appointments')->find(222);
    $second = DB::table('appointments')->find(231);

    expect((int) $first->cash_amount)->toBe(30000)
        ->and((int) $first->card_amount)->toBe(20000)
        ->and($second->cash_amount)->toBeNull()
        ->and((int) $second->card_amount)->toBe(120000);
});

it('leaves the price alone — that one is the cashier to decide', function () {
    insertErasedAppointment(222, 187);

    runRestoreMigration();

    expect((int) DB::table('appointments')->find(222)->price)->toBe(0);
});

it('is idempotent', function () {
    insertErasedAppointment(222, 187);
    insertErasedAppointment(231, 195);

    runRestoreMigration();
    $afterFirst = DB::table('appointments')->orderBy('id')->get()->toArray();

    runRestoreMigration();

    expect(DB::table('appointments')->orderBy('id')->get()->toArray())->toEqual($afterFirst);
});

it('never overwrites money the cashier has already put back by hand', function () {
    insertErasedAppointment(222, 187, ['cash_amount' => 60000, 'card_amount' => null]);

    runRestoreMigration();

    $row = DB::table('appointments')->find(222);

    expect((int) $row->cash_amount)->toBe(60000)
        ->and($row->card_amount)->toBeNull();
});

it('only touches a row where every guard field matches', function () {
    // Чужая база: те же id, но другой клиент, цена и статус — трогать нельзя.
    insertErasedAppointment(222, 900, ['price' => 50000]);
    insertErasedAppointment(231, 195, ['status' => 'pending']);

    runRestoreMigration();

    foreach ([222, 231] as $id) {
        $row = DB::table('appointments')->find($id);

        expect($row->cash_amount)->toBeNull("appointment {$id}")
            ->and($row->card_amount)->toBeNull("appointment {$id}");
    }
});

it('rolls back to the state the broken repair left behind', function () {
    insertErasedAppointment(222, 187);
    insertErasedAppointment(231, 195);

    runRestoreMigration();
    rollBackRestoreMigration();

    foreach ([222, 231] as $id) {
        $row = DB::table('appointments')->find($id);

        expect($row->cash_amount)->toBeNull("appointment {$id}")
            ->and($row->card_amount)->toBeNull("appointment {$id}");
    }
});

it('does not roll back money the cashier changed after the restore', function () {
    insertErasedAppointment(222, 187);

    runRestoreMigration();
    DB::table('appointments')->where('id', 222)->update(['cash_amount' => 50000]);

    rollBackRestoreMigration();

    expect((int) DB::table('appointments')->find(222)->cash_amount)->toBe(50000);
});
