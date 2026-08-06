<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function feedbackAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

function feedbackSuperAdmin(): User
{
    return User::factory()->create(['role' => Role::SUPER_ADMIN]);
}

/*
|--------------------------------------------------------------------------
| A successful save dispatches 'saved'
|--------------------------------------------------------------------------
*/

it('dispatches saved after creating a client', function () {
    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.clients')
        ->set('name', 'Тест Клиент')
        ->set('phone', '998901112233')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved after creating a service', function () {
    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.services')
        ->set('name_ru', 'Стрижка')
        ->set('name_uz', 'Soch olish')
        ->set('name_kaa', 'Shash alıw')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved after creating a product', function () {
    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.products')
        ->set('name', 'Шампунь мятный')
        ->set('selling_price', 30000)
        ->set('stock', 10)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved after saving settings', function () {
    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.settings')
        ->set('work_start', '10:00')
        ->set('work_end', '20:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved after creating a user', function () {
    Livewire::actingAs(feedbackSuperAdmin())
        ->test('pages.admin.users')
        ->set('name', 'Новый Админ')
        ->set('phone', '998901112244')
        ->set('password', 'secret123')
        ->set('role', 'admin')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved after creating an appointment', function () {
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 60000]);
    $service = Service::factory()->create();
    $client = Client::factory()->create();

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->set('barber_id', $barber->id)
        ->set('client_id', $client->id)
        ->set('form_date', '2026-08-10')
        ->set('form_start_time', '10:00')
        ->set('form_end_time', '11:00')
        ->set('selectedServices', [['service_id' => $service->id, 'amount' => 60000]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved after selling a product', function () {
    $product = Product::create([
        'name' => 'Гель для укладки',
        'selling_price' => 50000,
        'stock' => 10,
        'is_active' => true,
    ]);

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $product->id)
        ->set('payment_type', 'cash')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved after accepting a debt repayment', function () {
    $order = Order::create([
        'client_id' => Client::factory()->create()->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 50000,
    ]);

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 50000)
        ->call('payOrderDebt')
        ->assertHasNoErrors()
        ->assertDispatched('saved');
});

it('dispatches saved when an SMS toggle persists itself', function () {
    // У страницы нет кнопки сохранения: переключатель пишет настройку сразу,
    // и без подтверждения непонятно, сработал ли он вообще.
    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.sms.settings')
        ->set('remindersEnabled', false)
        ->assertDispatched('saved');

    expect(Setting::get('sms_enabled_reminder'))->toBe('0');
});

it('carries the page-specific confirmation text where one exists', function () {
    // Общая пилюля живёт в макете, поэтому текст приходит параметром события.
    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.settings')
        ->set('work_start', '10:00')
        ->set('work_end', '20:00')
        ->call('save')
        ->assertDispatched('saved', message: __('settings.saved'));

    $order = Order::create([
        'client_id' => Client::factory()->create()->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 50000,
    ]);

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 50000)
        ->call('payOrderDebt')
        ->assertDispatched('saved', message: __('debts.payment_saved'));
});

/*
|--------------------------------------------------------------------------
| A refused write must never claim to have saved
|--------------------------------------------------------------------------
*/

it('does not dispatch saved when the client phone is already taken', function () {
    Client::factory()->create(['phone' => '998901112233']);

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.clients')
        ->set('name', 'Дубликат')
        ->set('phone', '+998 90 111 22 33')
        ->call('save')
        ->assertHasErrors('phone')
        ->assertNotDispatched('saved');

    expect(Client::count())->toBe(1);
});

it('does not dispatch saved when an order payment split does not add up', function () {
    // Денежная операция: разбивка наличные+карта не сходится с ценой.
    $product = Product::create([
        'name' => 'Гель для укладки',
        'selling_price' => 50000,
        'stock' => 10,
        'is_active' => true,
    ]);

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $product->id)
        ->set('payment_type', 'both')
        ->set('cash_amount', 10000)
        ->set('card_amount', 10000)
        ->call('save')
        ->assertHasErrors('cash_amount')
        ->assertNotDispatched('saved');

    expect(Order::count())->toBe(0);
});

it('does not dispatch saved when an appointment is refused by its own guards', function (array $overrides, string $errorKey) {
    // Именно здесь двойной клик стоил дублирующегося оплаченного визита,
    // поэтому каждая собственная проверка save() обязана молчать про «Сохранено».
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 60000]);
    $service = Service::factory()->create();
    $client = Client::factory()->create();

    $component = Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->set('barber_id', $barber->id)
        ->set('client_id', $client->id)
        ->set('form_date', '2026-08-10')
        ->set('form_start_time', '10:00')
        ->set('form_end_time', '11:00')
        ->set('selectedServices', [['service_id' => $service->id, 'amount' => 60000]]);

    foreach ($overrides as $property => $value) {
        $component->set($property, is_callable($value) ? $value($service) : $value);
    }

    $component->call('save')
        ->assertHasErrors($errorKey)
        ->assertNotDispatched('saved');

    expect(Appointment::count())->toBe(0);
})->with([
    'no service picked' => [['selectedServices' => []], 'selectedServices'],
    'same service twice' => [[
        'selectedServices' => fn (Service $service) => [
            ['service_id' => $service->id, 'amount' => 30000],
            ['service_id' => $service->id, 'amount' => 30000],
        ],
    ], 'selectedServices'],
    'ends before it starts' => [['form_end_time' => '09:00'], 'form_end_time'],
]);

it('does not dispatch saved when a stock receipt exceeds the allowed batch', function () {
    $product = Product::create([
        'name' => 'Гель для укладки',
        'selling_price' => 50000,
        'stock' => 10,
        'is_active' => true,
    ]);

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.products')
        ->set('receiveQty.'.$product->id, 10000)
        ->call('receiveStock', $product->id)
        ->assertHasErrors('receiveQty.'.$product->id)
        ->assertNotDispatched('saved');

    expect($product->fresh()->stock)->toBe(10);
});

it('does not dispatch saved when a debt repayment is overpaid', function () {
    // Денежная операция: переплату по долгу нельзя молча принять и показать «Сохранено».
    $order = Order::create([
        'client_id' => Client::factory()->create()->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 999999)
        ->call('payOrderDebt')
        ->assertHasErrors('payAmount')
        ->assertNotDispatched('saved');
});

/*
|--------------------------------------------------------------------------
| Every admin submit button carries the loading contract
|--------------------------------------------------------------------------
*/

it('renders the submit button loading contract on the clients form', function () {
    $html = Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.clients')
        ->call('openCreate')
        ->html();

    expect($html)->toContain('wire:loading.attr="disabled"')
        ->and($html)->toContain('wire:target="save"');
});

it('renders the submit button loading contract on the debt payment modal, targeting its own action', function () {
    // Кнопка модалки долга целится в payOrderDebt, а не в общий save —
    // иначе спиннер и блокировка сработают на чужом запросе.
    $order = Order::create([
        'client_id' => Client::factory()->create()->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    $html = Livewire::actingAs(feedbackAdmin())
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->html();

    expect($html)->toContain('wire:loading.attr="disabled"')
        ->and($html)->toContain('wire:target="payOrderDebt"');
});

it('renders the toast listener in the admin layout', function () {
    $this->actingAs(feedbackAdmin())
        ->get(route('admin.clients'))
        ->assertOk()
        ->assertSee('saved.window', escape: false);
});
