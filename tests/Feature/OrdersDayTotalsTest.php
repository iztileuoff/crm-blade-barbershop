<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ordersAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('keeps the sticky mobile total bar in sync with the cart, matching the same total the layout uses everywhere else (#69)', function () {
    $admin = ordersAdmin();
    $shampoo = Product::create(['name' => 'Шампунь', 'stock' => 10, 'selling_price' => 25000]);
    $wax = Product::create(['name' => 'Воск', 'stock' => 10, 'selling_price' => 15000]);

    $page = Livewire::actingAs($admin)
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $shampoo->id)
        ->call('addToCart', $wax->id);

    // 25 000 + 15 000 — то же число обязано попасть в липкую панель снизу,
    // а не разойтись с тем, что реально лежит в корзине.
    expect($page->instance()->cartTotal())->toBe(40000);

    $html = $page->html();

    // Панель липнет ко дну на мобиле и садится в обычный поток на lg —
    // это одна и та же панель для обоих размеров экрана, не две разные.
    expect($html)
        ->toContain('sticky bottom-0 z-20')
        ->toContain('lg:static')
        ->toContain(__('orders.cart_count', ['count' => 2]))
        ->toContain(number_format(40000, 0, '.', ' ').' '.__('common.currency'));
});

it('shows received money, not the gross total that still includes unpaid debt', function () {
    $client = Client::factory()->create();

    // 100 000 всего, 40 000 в долг → в кассу в этот день попало только 60 000.
    Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    $page = Livewire::actingAs(ordersAdmin())->test('pages.admin.orders');

    expect($page->instance()->todayTotal)->toBe(60000)
        ->and($page->instance()->todayTurnover)->toBe(100000);
});

it('keeps received and turnover equal when nothing was sold on credit', function () {
    $client = Client::factory()->create();

    Order::create([
        'client_id' => $client->id,
        'total_price' => 75000,
        'payment_type' => 'cash',
    ]);

    $page = Livewire::actingAs(ordersAdmin())->test('pages.admin.orders');

    expect($page->instance()->todayTotal)->toBe(75000)
        ->and($page->instance()->todayTurnover)->toBe(75000);
});

it('labels received and turnover separately on the day card', function () {
    $client = Client::factory()->create();

    Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    $html = Livewire::actingAs(ordersAdmin())->test('pages.admin.orders')->html();

    expect($html)->toContain(__('orders.revenue_day'))
        ->toContain(__('orders.turnover_day'))
        ->toContain('60 000')
        ->toContain('100 000');
});

it('sums received amounts across cash, card and partly-debt orders, keeping turnover as the gross total', function () {
    $client = Client::factory()->create();

    // Нал 50 000, карта 30 000, и продажа 100 000 из которых 40 000 в долг → получено 60 000.
    Order::create(['client_id' => $client->id, 'total_price' => 50000, 'payment_type' => 'cash']);
    Order::create(['client_id' => $client->id, 'total_price' => 30000, 'payment_type' => 'card']);
    Order::create(['client_id' => $client->id, 'total_price' => 100000, 'payment_type' => 'cash', 'debt_amount' => 40000]);

    $page = Livewire::actingAs(ordersAdmin())->test('pages.admin.orders');

    // касса: 50 000 + 30 000 + 60 000 = 140 000
    expect($page->instance()->todayTotal)->toBe(140000)
        // оборот: 50 000 + 30 000 + 100 000 = 180 000
        ->and($page->instance()->todayTurnover)->toBe(180000);
});

it('lets an order sold entirely on credit contribute nothing to the day takings', function () {
    $client = Client::factory()->create();

    Order::create([
        'client_id' => $client->id,
        'total_price' => 90000,
        'payment_type' => 'cash',
        'debt_amount' => 90000,
    ]);

    $page = Livewire::actingAs(ordersAdmin())->test('pages.admin.orders');

    expect($page->instance()->todayTotal)->toBe(0)
        ->and($page->instance()->todayTurnover)->toBe(90000);
});

it('does not let a debt repayment made on a later day change an earlier day takings card', function () {
    $admin = ordersAdmin();
    $client = Client::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Tashkent'));
    $order = Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 40000,
    ]);

    $ordersOn = fn () => Livewire::actingAs($admin)
        ->test('pages.admin.orders')
        ->set('date', '2026-08-01')
        ->instance();

    expect($ordersOn()->todayTotal)->toBe(60000);

    // Долг приносят только 6 августа — на другой странице (касса долгов).
    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayOrder', $order->id)
        ->set('payAmount', 40000)
        ->call('payOrderDebt');

    // Карточка кассы за 1 августа не пересчитывается задним числом.
    expect($ordersOn()->todayTotal)->toBe(60000)
        ->and($ordersOn()->todayTurnover)->toBe(100000);

    Carbon::setTestNow();
});
