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

/*
|--------------------------------------------------------------------------
| Navigation: day/month stay in sync, "forward" never outruns today (#64)
|--------------------------------------------------------------------------
*/

it('moves the month with the day when the date picker changes', function () {
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('date', '2026-05-15');

    expect($component->get('month'))->toBe('2026-05');
});

it('falls back to today when the date field arrives empty or impossible', function (string $value) {
    // Carbon::parse('0000-00-00') не бросает, а тихо даёт -0001 год — и он
    // утекал бы и в шапку дня, и в $month следующей же строкой.
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('date', $value);

    expect($component->get('date'))->toBe('2026-08-06')
        ->and($component->get('month'))->toBe('2026-08');

    $component->assertOk()->assertSee(Str::ucfirst(Carbon::parse('2026-08-06')->translatedFormat('j F Y')));
})->with(['empty' => [''], 'zero date' => ['0000-00-00'], 'garbage' => ['not-a-date'], 'impossible day' => ['2026-02-31']]);

it('crosses the month boundary when stepping the day backwards past the 1st', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Tashkent'));

    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->call('previousPeriod');

    expect($component->get('date'))->toBe('2026-07-31')
        ->and($component->get('month'))->toBe('2026-07');
});

it('snaps the day tab into whichever month the month tab was left on', function () {
    // На вкладке "Месяц" стрелками ушли в июль, а $date всё ещё в августе —
    // возврат на "День" должен показать день из уже выбранного месяца.
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->call('previousPeriod')
        ->call('switchTab', 'day');

    expect($component->get('activeTab'))->toBe('day')
        ->and($component->get('date'))->toBe('2026-07-01')
        ->and($component->get('month'))->toBe('2026-07');
});

it('does not disturb an already-synced day when switching tabs', function () {
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('date', '2026-08-20')
        ->set('activeTab', 'month')
        ->call('switchTab', 'day');

    expect($component->get('date'))->toBe('2026-08-20')
        ->and($component->get('month'))->toBe('2026-08');
});

it('refuses to step the day tab past today even when nextPeriod is called directly', function () {
    // Кнопка «вперёд» дисейблится в разметке, но сюда её можно и обойти.
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->call('nextPeriod');

    expect($component->get('date'))->toBe('2026-08-06');
});

it('refuses to step the month tab past the current month even when nextPeriod is called directly', function () {
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->call('nextPeriod');

    expect($component->get('month'))->toBe('2026-08');
});

it('lets nextPeriod step forward again once a past day has been selected', function () {
    // Сама проверка не залипла навсегда: шаг назад открывает шаг вперёд.
    $component = Livewire::actingAs(monthAdmin())
        ->test('pages.admin.dashboard')
        ->call('previousPeriod')
        ->call('nextPeriod');

    expect($component->get('date'))->toBe('2026-08-06');
});
