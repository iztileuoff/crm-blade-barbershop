<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\DebtPayment;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function debtAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

function dashboardOn(User $admin, string $date): object
{
    return Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'day')
        ->set('date', $date)
        ->instance();
}

it('keeps the original day untouched and books the payment on the day it was accepted', function () {
    $admin = debtAdmin();
    $client = Client::factory()->create();

    // Продажа 27 мая: 100 000, из них 40 000 в долг → в кассу 27 мая попало 60 000.
    Carbon::setTestNow(Carbon::parse('2026-05-27 11:00:00', 'Asia/Tashkent'));
    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    expect(dashboardOn($admin, '2026-05-27')->receivedTotal())->toBe(60000);

    // Долг приносят 2 августа.
    Carbon::setTestNow(Carbon::parse('2026-08-02 09:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 40000)
        ->set('payPaymentType', 'card')
        ->call('payOrderDebt');

    // Касса 27 мая не изменилась…
    expect(dashboardOn($admin, '2026-05-27')->receivedTotal())->toBe(60000);

    // …а деньги попали в кассу 2 августа, причём именно на карту.
    $payday = dashboardOn($admin, '2026-08-02');
    expect($payday->receivedTotal())->toBe(40000)
        ->and($payday->cardTotal())->toBe(40000)
        ->and($payday->cashTotal())->toBe(0)
        ->and($payday->debtRepaidToday())->toBe(40000);

    Carbon::setTestNow();
});

it('reduces the outstanding debt without rewriting the issued amount', function () {
    $admin = debtAdmin();
    $client = Client::factory()->create();

    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 15000)
        ->call('payOrderDebt');

    $order->refresh();

    expect($order->debt_amount)->toBe(40000)
        ->and($order->outstandingDebt)->toBe(25000)
        ->and($order->hasDebt)->toBeTrue()
        ->and(DebtPayment::count())->toBe(1);
});

it('never records more than the debt when the same payment is submitted twice', function () {
    $admin = debtAdmin();
    $client = Client::factory()->create();

    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 50000,
    ]);

    // Двойной клик: второй запрос приходит с тем же payAmount по той же операции.
    $component = Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 50000);

    $component->call('payOrderDebt');

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 50000)
        ->call('payOrderDebt');

    expect((int) DebtPayment::sum('amount'))->toBe(50000)
        ->and($order->fresh()->outstandingDebt)->toBe(0);
});

it('drops a fully repaid debt off the debts page', function () {
    $admin = debtAdmin();
    $client = Client::factory()->create();

    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    $component = Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 40000)
        ->call('payOrderDebt');

    expect($component->instance()->orderDebts())->toHaveCount(0)
        ->and($component->instance()->totalOrderDebt())->toBe(0);
});

it('never accepts more than the outstanding debt', function () {
    $admin = debtAdmin();
    $client = Client::factory()->create();

    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 999999)
        ->call('payOrderDebt');

    expect((int) DebtPayment::sum('amount'))->toBe(40000)
        ->and($order->fresh()->outstandingDebt)->toBe(0);
});

it('removes debt payments when the operation itself is deleted', function () {
    $admin = debtAdmin();
    $client = Client::factory()->create();

    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 40000)
        ->call('payOrderDebt');

    expect(DebtPayment::count())->toBe(1);

    // Осиротевший платёж продолжал бы считаться приходом в кассе.
    $order->delete();

    expect(DebtPayment::count())->toBe(0);
});

it('shows the client outstanding debt, not the amount originally issued', function () {
    $admin = debtAdmin();
    $client = Client::factory()->create();

    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 25000)
        ->call('payOrderDebt');

    $profile = Livewire::actingAs($admin)
        ->test('pages.admin.clients.show', ['client' => $client])
        ->instance();

    expect($profile->totalDebt())->toBe(15000);
});

it('drops orphaned payments out of the register when the client is cascade-deleted', function () {
    $admin = debtAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));

    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(9, 0),
        'ends_at' => now()->setTime(10, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 40000)
        ->call('payAppointmentDebt');

    expect(dashboardOn($admin, '2026-08-06')->receivedTotal())->toBe(100000);

    // Каскад в БД сносит запись, не поднимая событий Eloquent, — платёж остаётся сиротой.
    $client->delete();

    expect(dashboardOn($admin, '2026-08-06')->receivedTotal())->toBe(0);

    Carbon::setTestNow();
});

it('reads the eager-loaded payment sum without firing another query', function () {
    $order = Order::create(['total_price' => 100000, 'payment_type' => 'cash', 'debt_amount' => 40000]);
    DebtPayment::create([
        'payable_type' => $order->getMorphClass(),
        'payable_id' => $order->id,
        'amount' => 15000,
        'payment_type' => 'cash',
        'paid_at' => now(),
    ]);

    // Операция без единого погашения: withAggregate отдаёт NULL, и наивная
    // проверка через `??` уводила бы в запрос на каждое чтение.
    Order::create(['total_price' => 50000, 'payment_type' => 'cash', 'debt_amount' => 10000]);

    $rows = Order::query()
        ->withSum('debtPayments as debt_paid_total', 'amount')
        ->orderBy('id')
        ->get();

    DB::enableQueryLog();
    $outstanding = $rows->map(fn (Order $o) => $o->outstandingDebt)->all();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($outstanding)->toBe([25000, 10000])
        ->and($queries)->toBe(0);
});

it('refuses to lower a debt below what has already been collected', function () {
    $admin = debtAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();
    $service = Service::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Tashkent'));
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);
    $appointment->services()->sync([$service->id => ['amount' => 100000]]);

    Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 40000)
        ->call('payAppointmentDebt');

    // 1 авг 60 000 + 3 авг 40 000 = 100 000 — ровно цена услуги.
    expect(dashboardOn($admin, '2026-08-01')->receivedTotal())->toBe(60000)
        ->and(dashboardOn($admin, '2026-08-03')->receivedTotal())->toBe(40000);

    // Попытка снять долг с уже погашенной записи должна быть отклонена.
    Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.appointments')
        ->call('edit', $appointment->id)
        ->set('debtEnabled', false)
        ->set('debt_amount', null)
        ->call('save')
        ->assertHasErrors('debt_amount');

    expect($appointment->fresh()->debt_amount)->toBe(40000)
        ->and(dashboardOn($admin, '2026-08-01')->receivedTotal())->toBe(60000)
        ->and(dashboardOn($admin, '2026-08-03')->receivedTotal())->toBe(40000);

    Carbon::setTestNow();
});

it('counts an appointment debt repayment in the payment-day cash register', function () {
    $admin = debtAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00', 'Asia/Tashkent'));
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 60000,
        'payment_type' => 'cash',
        'debt_amount' => 60000,
    ]);

    expect(dashboardOn($admin, '2026-07-19')->receivedTotal())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 60000)
        ->call('payAppointmentDebt');

    expect(dashboardOn($admin, '2026-07-19')->receivedTotal())->toBe(0)
        ->and(dashboardOn($admin, '2026-08-06')->receivedTotal())->toBe(60000);

    Carbon::setTestNow();
});
