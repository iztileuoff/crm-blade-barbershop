<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function debtListAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

/** Продажа с непогашенным долгом на указанного клиента. */
function orderDebt(Client $client, int $debt = 10000): Order
{
    return Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => $debt,
    ]);
}

it('paginates both debt tables', function () {
    $client = Client::factory()->create();
    $barber = Barber::factory()->create();

    for ($i = 0; $i < 30; $i++) {
        orderDebt($client);
        Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'starts_at' => now()->subDays($i),
            'ends_at' => now()->subDays($i)->addHour(),
            'price' => 100000,
            'payment_type' => 'cash',
            'debt_amount' => 10000,
        ]);
    }

    $page = Livewire::actingAs(debtListAdmin())->test('pages.admin.debts');

    expect($page->instance()->orderDebts)->toHaveCount(25)
        ->and($page->instance()->orderDebts->total())->toBe(30)
        ->and($page->instance()->appointmentDebts)->toHaveCount(25)
        ->and($page->instance()->appointmentDebts->total())->toBe(30);
});

it('sums every debt, not just the loaded page', function () {
    $client = Client::factory()->create();

    for ($i = 0; $i < 30; $i++) {
        orderDebt($client, 10000);
    }

    // 30 × 10 000 — итог обязан быть по всем строкам, а не по 25 загруженным.
    $page = Livewire::actingAs(debtListAdmin())->test('pages.admin.debts');

    expect($page->instance()->totalOrderDebt)->toBe(300000)
        ->and($page->instance()->grandTotal)->toBe(300000);
});

it('subtracts repayments from the total', function () {
    $client = Client::factory()->create();
    $order = orderDebt($client, 50000);

    $page = Livewire::actingAs(debtListAdmin())->test('pages.admin.debts');
    expect($page->instance()->totalOrderDebt)->toBe(50000);

    $page->call('openPayOrder', $order->id)
        ->set('payAmount', 20000)
        ->call('payOrderDebt')
        ->assertHasNoErrors();

    expect($page->instance()->totalOrderDebt)->toBe(30000);
});

it('searches debtors by name and by the phone format the interface shows', function () {
    $ali = Client::factory()->create(['name' => 'Али', 'phone' => '998901234567']);
    $bek = Client::factory()->create(['name' => 'Бек', 'phone' => '998911112233']);

    orderDebt($ali, 10000);
    orderDebt($bek, 20000);

    $page = Livewire::actingAs(debtListAdmin())->test('pages.admin.debts');

    $page->set('search', 'Али');
    expect($page->instance()->orderDebts->total())->toBe(1)
        ->and($page->instance()->totalOrderDebt)->toBe(10000);

    $page->set('search', '+998 91 111 22 33');
    expect($page->instance()->orderDebts->total())->toBe(1)
        ->and($page->instance()->totalOrderDebt)->toBe(20000);
});

it('resets both pages when the search changes', function () {
    $client = Client::factory()->create(['name' => 'Али']);

    for ($i = 0; $i < 60; $i++) {
        orderDebt($client);
    }

    Livewire::actingAs(debtListAdmin())
        ->test('pages.admin.debts')
        ->call('gotoPage', 3, 'ordersPage')
        ->assertSet('paginators.ordersPage', 3)
        ->set('search', 'Бек')
        ->assertSet('paginators.ordersPage', 1);
});

it('says nothing was found instead of claiming there are no debts', function () {
    orderDebt(Client::factory()->create(['name' => 'Али']));

    Livewire::actingAs(debtListAdmin())
        ->test('pages.admin.debts')
        ->set('search', 'Никого')
        ->assertSee(__('debts.search_empty'))
        ->assertDontSee(__('debts.all_paid'));
});

it('opens the pay modal for a record that is not on the first page', function () {
    $client = Client::factory()->create();
    $orders = collect(range(1, 30))->map(fn () => orderDebt($client, 10000));
    $last = $orders->sortBy('created_at')->first();

    Livewire::actingAs(debtListAdmin())
        ->test('pages.admin.debts')
        ->call('openPayOrder', $last->id)
        ->assertSet('payAmount', 10000)
        ->assertSee($client->name);
});
