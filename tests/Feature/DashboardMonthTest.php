<?php

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function monthAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Продажа текущего месяца — она обязана попасть в месячные карточки. */
function augustSale(): Order
{
    return Order::create([
        'total_price' => 50000,
        'payment_type' => 'cash',
    ]);
}

it('shows the current month when the month field arrives empty', function () {
    augustSale();

    // Очистка поля месяца в браузере шлёт пустую строку: Carbon::parse('-01')
    // молча давал декабрь 1969-го, и все карточки уезжали в пустоту.
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '');

    expect($component->instance()->monthlyProductRevenue)->toBe(50000)
        ->and($component->instance()->monthString)->toBe(
            Str::ucfirst(Carbon::parse('2026-08-01')->translatedFormat('F Y'))
        );
});

it('shows the current month when the month field is malformed', function () {
    augustSale();

    // `2026-13` кидал InvalidFormatException — белый экран на странице зарплат.
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-13');

    expect($component->instance()->monthlyProductRevenue)->toBe(50000);
});

it('keeps the header and the data on the same month', function () {
    augustSale();

    Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Tashkent'));
    $julySale = Order::create(['total_price' => 30000, 'payment_type' => 'cash']);
    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));

    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-07');

    expect($component->instance()->monthlyProductRevenue)->toBe(30000)
        ->and($component->instance()->monthString)->toBe(
            Str::ucfirst(Carbon::parse('2026-07-01')->translatedFormat('F Y'))
        )
        ->and($julySale->fresh()->total_price)->toBe(30000);
});

it('builds the daily chart for the fallback month, not for 1969', function () {
    augustSale();

    $chart = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '')
        ->instance()
        ->dailyChartData;

    expect($chart)->toHaveCount(31)
        ->and($chart[0]['date'])->toBe('2026-08-01')
        ->and(collect($chart)->sum('product'))->toBe(50000);
});
