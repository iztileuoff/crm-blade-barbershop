<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\PaymentSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function moneyAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

/**
 * Форма записи, заполненная одной услугой на заданную сумму.
 */
function appointmentForm(User $admin, int $amount): object
{
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => $amount]);
    $service = Service::factory()->create();
    $client = Client::factory()->create();

    return Livewire::actingAs($admin)
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->set('barber_id', $barber->id)
        ->set('client_id', $client->id)
        ->set('form_date', '2026-08-06')
        ->set('form_start_time', '10:00')
        ->set('form_end_time', '11:00')
        ->set('selectedServices', [['service_id' => $service->id, 'amount' => $amount]]);
}

it('rejects a debt larger than the price of an appointment', function () {
    appointmentForm(moneyAdmin(), 60000)
        ->set('payment_type', 'cash')
        ->set('debt_amount', 150000)
        ->call('save')
        ->assertHasErrors('debt_amount');

    expect(Appointment::count())->toBe(0);
});

it('rejects a mixed payment whose split does not add up', function () {
    appointmentForm(moneyAdmin(), 60000)
        ->set('payment_type', 'both')
        ->set('cash_amount', 60000)
        ->set('card_amount', 60000)
        ->call('save')
        ->assertHasErrors('cash_amount');

    expect(Appointment::count())->toBe(0);
});

it('rejects a mixed payment with an empty split', function () {
    appointmentForm(moneyAdmin(), 100000)
        ->set('payment_type', 'both')
        ->call('save')
        ->assertHasErrors('cash_amount');

    expect(Appointment::count())->toBe(0);
});

it('accepts a mixed payment that reconciles with price minus debt', function () {
    appointmentForm(moneyAdmin(), 100000)
        ->set('payment_type', 'both')
        ->set('cash_amount', 50000)
        ->set('card_amount', 30000)
        ->set('debt_amount', 20000)
        ->call('save')
        ->assertHasNoErrors();

    $appointment = Appointment::firstOrFail();

    expect($appointment->receivedAmount)->toBe(80000)
        ->and($appointment->cashReceived + $appointment->cardReceived)->toBe(80000);
});

it('still enforces the attribute rules that used to be dropped by save', function () {
    // barber_id объявлен через #[Validate('required|...')]; раньше validate()
    // с явным массивом правил его игнорировал.
    $service = Service::factory()->create();

    Livewire::actingAs(moneyAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->set('barber_id', null)
        ->set('form_date', '2026-08-06')
        ->set('form_start_time', '10:00')
        ->set('form_end_time', '11:00')
        ->set('selectedServices', [['service_id' => $service->id, 'amount' => 50000]])
        ->call('save')
        ->assertHasErrors('barber_id');

    expect(Appointment::count())->toBe(0);
});

it('rejects a product sale whose split does not add up', function () {
    $product = Product::create([
        'name' => 'Гель',
        'selling_price' => 50000,
        'purchase_price' => 30000,
        'stock' => 10,
        'is_active' => true,
    ]);

    Livewire::actingAs(moneyAdmin())
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $product->id)
        ->set('payment_type', 'both')
        ->set('cash_amount', 10000)
        ->set('card_amount', 10000)
        ->call('save')
        ->assertHasErrors('cash_amount');

    expect(Order::count())->toBe(0);
});

it('rejects a product sale with a debt larger than the total', function () {
    $product = Product::create([
        'name' => 'Гель',
        'selling_price' => 50000,
        'purchase_price' => 30000,
        'stock' => 10,
        'is_active' => true,
    ]);

    $client = Client::factory()->create();

    Livewire::actingAs(moneyAdmin())
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $product->id)
        ->set('client_id', $client->id)
        ->set('payment_type', 'cash')
        ->set('debt_amount', 90000)
        ->call('save')
        ->assertHasErrors('debt_amount');

    expect(Order::count())->toBe(0);
});

it('rejects negative amounts on a product sale before they reach unsigned columns', function () {
    $product = Product::create([
        'name' => 'Гель',
        'selling_price' => 100000,
        'purchase_price' => 30000,
        'stock' => 10,
        'is_active' => true,
    ]);

    // −50 000 + 150 000 сходится с ценой, поэтому PaymentSplit это пропускает;
    // на MySQL отрицательное значение в unsignedInteger даёт 500.
    Livewire::actingAs(moneyAdmin())
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $product->id)
        ->set('payment_type', 'both')
        ->set('cash_amount', -50000)
        ->set('card_amount', 150000)
        ->call('save')
        ->assertHasErrors('cash_amount');

    expect(Order::count())->toBe(0);
});

it('rejects an unknown payment type on a product sale', function () {
    $product = Product::create([
        'name' => 'Гель',
        'selling_price' => 50000,
        'purchase_price' => 30000,
        'stock' => 10,
        'is_active' => true,
    ]);

    // Иначе строка сохранится, а каст в PaymentType будет ронять страницу вечно.
    Livewire::actingAs(moneyAdmin())
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $product->id)
        ->set('payment_type', 'x')
        ->call('save')
        ->assertHasErrors('payment_type');

    expect(Order::count())->toBe(0);
});

it('sends an empty mixed split to cash, matching the repair migration', function () {
    $order = Order::create(['total_price' => 100000, 'payment_type' => 'both']);

    expect($order->cashReceived)->toBe(100000)
        ->and($order->cardReceived)->toBe(0)
        ->and($order->cashReceived + $order->cardReceived)->toBe($order->receivedAmount);
});

it('lets a fully-on-credit operation be saved without a split', function () {
    expect(PaymentSplit::errors('both', 100000, null, null, 100000))->toBeEmpty();
});

it('reproduces every broken row from the dump as a validation error', function () {
    // Строки из blade_barber.sql, которые прежний ввод пропустил в базу.
    $cases = [
        ['both', 100000, null, null, 0],      // #67  — пустая разбивка
        ['both', 60000, 60000, 60000, 0],     // #673 — разбивка вдвое больше цены
        ['both', 120000, 60000, 40000, 0],    // #525 — недобор 20 000
        ['cash', 60000, null, null, 150000],  // #1032 — долг больше цены
        ['cash', 120000, null, null, 133000], // #1270 — долг больше цены
    ];

    foreach ($cases as [$type, $total, $cash, $card, $debt]) {
        expect(PaymentSplit::errors($type, $total, $cash, $card, $debt))->not->toBeEmpty();
    }

    // А корректные операции проходят.
    expect(PaymentSplit::errors('both', 100000, 60000, 40000, 0))->toBeEmpty()
        ->and(PaymentSplit::errors('cash', 100000, null, null, 30000))->toBeEmpty()
        ->and(PaymentSplit::errors('card', 100000, null, null, null))->toBeEmpty();
});
