<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function kassaAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('computes received, cash and card amounts for an order by payment type', function () {
    $cash = Order::create(['total_price' => 40000, 'payment_type' => 'cash']);
    expect($cash->receivedAmount)->toBe(40000)
        ->and($cash->cashReceived)->toBe(40000)
        ->and($cash->cardReceived)->toBe(0);

    $card = Order::create(['total_price' => 50000, 'payment_type' => 'card']);
    expect($card->cashReceived)->toBe(0)
        ->and($card->cardReceived)->toBe(50000);

    // Смешанная оплата с долгом: получено = нал + карта, долг отдельно.
    $both = Order::create([
        'total_price' => 80000,
        'payment_type' => 'both',
        'cash_amount' => 30000,
        'card_amount' => 30000,
        'debt_amount' => 20000,
    ]);
    expect($both->receivedAmount)->toBe(60000)
        ->and($both->cashReceived)->toBe(30000)
        ->and($both->cardReceived)->toBe(30000);

    // Долг при наличной оплате уменьшает фактически полученное.
    $cashWithDebt = Order::create(['total_price' => 30000, 'payment_type' => 'cash', 'debt_amount' => 5000]);
    expect($cashWithDebt->receivedAmount)->toBe(25000)
        ->and($cashWithDebt->cashReceived)->toBe(25000)
        ->and($cashWithDebt->cardReceived)->toBe(0);
});

it('computes received, cash and card amounts for an appointment', function () {
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'both',
        'cash_amount' => 40000,
        'card_amount' => 30000,
        'debt_amount' => 30000,
    ]);

    expect($appointment->receivedAmount)->toBe(70000)
        ->and($appointment->cashReceived)->toBe(40000)
        ->and($appointment->cardReceived)->toBe(30000);
});

it('aggregates the cash register totals for the day across services and products', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    $makeAppointment = function (array $attrs) use ($barber, $client): Appointment {
        return Appointment::create(array_merge([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'starts_at' => now()->setTime(12, 0),
            'ends_at' => now()->setTime(13, 0),
            'status' => AppointmentStatus::Completed,
        ], $attrs));
    };

    $makeAppointment(['price' => 100000, 'payment_type' => 'cash']);
    $makeAppointment(['price' => 50000, 'payment_type' => 'card']);
    $makeAppointment(['price' => 80000, 'payment_type' => 'both', 'cash_amount' => 30000, 'card_amount' => 30000, 'debt_amount' => 20000]);
    // Незавершённая запись не должна попадать в кассу.
    $makeAppointment(['price' => 999999, 'payment_type' => 'cash', 'status' => AppointmentStatus::Pending]);

    Order::create(['total_price' => 40000, 'payment_type' => 'cash']);
    Order::create(['total_price' => 30000, 'payment_type' => 'cash', 'debt_amount' => 5000, 'client_id' => $client->id]);

    $instance = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'day')
        ->set('date', '2026-06-27')
        ->instance();

    // нал: 100000 + 30000 (услуги) + 40000 + 25000 (товары) = 195000
    expect($instance->cashTotal())->toBe(195000)
        // карта: 50000 + 30000 (услуги) = 80000
        ->and($instance->cardTotal())->toBe(80000)
        ->and($instance->receivedTotal())->toBe(275000)
        // долг: 20000 (услуга) + 5000 (товар) = 25000
        ->and($instance->debtIssuedToday())->toBe(25000);

    Carbon::setTestNow();
});

it('aggregates the cash register totals for the month', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'card',
    ]);

    Order::create(['total_price' => 60000, 'payment_type' => 'cash', 'debt_amount' => 10000, 'client_id' => $client->id]);

    $instance = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-06')
        ->instance();

    expect($instance->monthlyCashTotal())->toBe(50000)
        ->and($instance->monthlyCardTotal())->toBe(100000)
        ->and($instance->monthlyReceivedTotal())->toBe(150000)
        ->and($instance->monthlyDebtIssued())->toBe(10000);

    Carbon::setTestNow();
});
