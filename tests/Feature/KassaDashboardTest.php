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

function dashboard(User $admin, string $date): object
{
    return Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'day')
        ->set('date', $date)
        ->instance();
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

it('keeps the received total equal to its own breakdown even on a broken split', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 60000]);
    $client = Client::factory()->create();

    // Ровно случай #673/#1110 из дампа: цена 60 000, а в разбивке 120 000.
    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 60000,
        'payment_type' => 'both',
        'cash_amount' => 60000,
        'card_amount' => 60000,
    ]);

    // И случай #67: «смешанный» выбран, а разбивка пустая.
    Order::create([
        'client_id' => $client->id,
        'total_price' => 100000,
        'payment_type' => 'both',
    ]);

    $instance = dashboard($admin, '2026-07-26');

    expect($instance->receivedTotal())->toBe(160000)
        // заголовок сходится со своей расшифровкой…
        ->and($instance->serviceReceived() + $instance->productReceived())->toBe($instance->receivedTotal())
        // …и с разбивкой нал/карта
        ->and($instance->cashTotal() + $instance->cardTotal())->toBe($instance->receivedTotal())
        // обе строки помечены как битые
        ->and($instance->brokenOperationsCount())->toBe(2);

    Carbon::setTestNow();
});

it('accrues salary from money received, not from turnover', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 100, 'price' => 120000]);
    $client = Client::factory()->create();

    // Запись #1270 из дампа: услуга на 120 000 целиком ушла в долг.
    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 120000,
        'payment_type' => 'cash',
        'debt_amount' => 120000,
    ]);

    $stat = dashboard($admin, '2026-08-06')->barberStats()->firstWhere('id', $barber->id);

    // В кассу не пришло ничего — начислять не с чего.
    expect($stat->revenue)->toBe(120000)
        ->and($stat->received)->toBe(0)
        ->and($stat->salary)->toBe(0)
        ->and($stat->remainder)->toBe(0);

    Carbon::setTestNow();
});

it('keeps a deactivated barber salary in the monthly report', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 200000,
        'payment_type' => 'cash',
    ]);

    $before = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-07')
        ->instance();

    $salaryBefore = $before->monthlyTotalSalary();
    $profitBefore = $before->companyProfit();

    expect($salaryBefore)->toBe(100000);

    // Мастера увольняют — отчёт за прошедший месяц меняться не должен.
    $barber->update(['is_active' => false]);

    $after = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-07')
        ->instance();

    $stat = $after->monthlyBarberStats()->firstWhere('id', $barber->id);

    expect($after->monthlyTotalSalary())->toBe($salaryBefore)
        ->and($after->companyProfit())->toBe($profitBefore)
        ->and($stat)->not->toBeNull()
        ->and($stat->isActive)->toBeFalse();

    Carbon::setTestNow();
});

it('separates profit on turnover from profit actually in the register', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 200000,
        'payment_type' => 'cash',
        'debt_amount' => 50000,
    ]);

    $instance = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-07')
        ->instance();

    // Оборот 200 000, получено 150 000, ЗП 50% от полученного = 75 000.
    expect($instance->monthlyTotalRevenue())->toBe(200000)
        ->and($instance->monthlyReceivedTotal())->toBe(150000)
        ->and($instance->monthlyTotalSalary())->toBe(75000)
        ->and($instance->companyProfit())->toBe(125000)
        ->and($instance->companyProfitInCash())->toBe(75000)
        ->and($instance->monthlyNotCollected())->toBe(50000);

    Carbon::setTestNow();
});

it('keeps the barber row arithmetic honest: paid − salary = remainder', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 200000]);
    $client = Client::factory()->create();

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 200000,
        'payment_type' => 'cash',
        'debt_amount' => 50000,
    ]);

    $stat = dashboard($admin, '2026-07-15')->barberStats()->firstWhere('id', $barber->id);

    expect($stat->revenue)->toBe(200000)
        ->and($stat->received)->toBe(150000)
        ->and($stat->salary)->toBe(75000)
        ->and($stat->received - $stat->salary)->toBe($stat->remainder);

    Carbon::setTestNow();
});

it('does not flag a mismatch when the percent changed mid-period', function () {
    $admin = kassaAdmin();
    $month = Carbon::now('Asia/Tashkent')->startOfMonth();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    $make = function (Carbon $at) use ($barber, $client) {
        return Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'starts_at' => $at,
            'ends_at' => $at->copy()->addHour(),
            'status' => AppointmentStatus::Completed,
            'price' => 100000,
            'payment_type' => 'cash',
        ]);
    };

    $make($month->copy()->addDays(2)->setTime(12, 0));
    $barber->update(['salary_percent' => 70]);
    $make($month->copy()->addDays(3)->setTime(12, 0));

    $stat = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', $month->format('Y-m'))
        ->instance()
        ->monthlyBarberStats()
        ->firstWhere('id', $barber->id);

    // Средневзвешенные 60% — законный результат смены ставки, а не поломка.
    expect($stat->salaryPercent)->toBe(60)
        ->and($stat->salary)->toBe(120000);
});

it('accrues salary on a debt collected inside the same payroll month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 100000,
    ]);

    $monthly = fn () => Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-08')
        ->instance();

    // Пока деньги не пришли — начислять не с чего.
    expect($monthly()->monthlyTotalSalary())->toBe(0);

    // 6 августа клиент приносит долг — тот же расчётный месяц.
    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 100000)
        ->call('payAppointmentDebt');

    $instance = $monthly();

    expect($instance->monthlyReceivedTotal())->toBe(100000)
        ->and($instance->monthlyTotalSalary())->toBe(50000)
        ->and($instance->companyProfitInCash())->toBe(50000);

    Carbon::setTestNow();
});

it('makes the sum of daily salaries equal the monthly salary', function () {
    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Tashkent'));
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 100000,
    ]);

    // Долг приносят 6 августа — другой день, тот же расчётный месяц.
    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 100000)
        ->call('payAppointmentDebt');

    $dailyTotal = 0;
    for ($day = 1; $day <= 31; $day++) {
        $date = sprintf('2026-08-%02d', $day);
        $dailyTotal += (int) dashboard($admin, $date)->barberStats()->sum('salary');
    }

    $monthly = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-08')
        ->instance()
        ->monthlyTotalSalary();

    expect($monthly)->toBe(50000)
        ->and($dailyTotal)->toBe($monthly);

    Carbon::setTestNow();
});

it('leaves a closed month untouched when the debt is collected later', function () {
    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'Asia/Tashkent'));
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 100000,
    ]);

    // Деньги приносят уже в августе — июльская зарплата пересчитываться не должна.
    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 100000)
        ->call('payAppointmentDebt');

    $monthly = fn (string $month) => Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', $month)
        ->instance();

    // Июль закрыт и не пересчитывается…
    expect($monthly('2026-07')->monthlyTotalSalary())->toBe(0)
        // …а доля начисляется в августе, когда деньги реально пришли.
        ->and($monthly('2026-08')->monthlyTotalSalary())->toBe(50000);

    Carbon::setTestNow();
});

it('books the repayment share on the day the money arrived, not the visit day', function () {
    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Tashkent'));
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 100000,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Tashkent'));
    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 100000)
        ->call('payAppointmentDebt');

    $rowOn = fn (string $date) => dashboard($admin, $date)->barberStats()->firstWhere('id', $barber->id);

    // День услуги: денег не было — начислять не с чего.
    expect($rowOn('2026-08-01')->received)->toBe(0)
        ->and($rowOn('2026-08-01')->salary)->toBe(0)
        // День платежа: деньги пришли — здесь и доля.
        ->and($rowOn('2026-08-06')->received)->toBe(100000)
        ->and($rowOn('2026-08-06')->salary)->toBe(50000)
        ->and($rowOn('2026-08-06')->remainder)->toBe(50000);

    Carbon::setTestNow();
});

it('computes the cash register remainder (revenue − salary) per barber for the day', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 60, 'price' => 100000]);
    $client = Client::factory()->create();

    foreach ([100000, 100000] as $price) {
        Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'starts_at' => now()->setTime(12, 0),
            'ends_at' => now()->setTime(13, 0),
            'status' => AppointmentStatus::Completed,
            'price' => $price,
            'payment_type' => 'cash',
        ]);
    }

    $instance = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'day')
        ->set('date', '2026-06-27')
        ->instance();

    $stat = $instance->barberStats()->firstWhere('id', $barber->id);

    // выручка 200000, ЗП 60% = 120000, остаток в кассе = 80000
    expect($stat->revenue)->toBe(200000)
        ->and($stat->salary)->toBe(120000)
        ->and($stat->remainder)->toBe(80000)
        ->and((int) $instance->barberStats()->sum('remainder'))->toBe(80000);

    Carbon::setTestNow();
});

it('computes the cash register remainder per barber for the month', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Tashkent'));

    $admin = kassaAdmin();
    $barber = Barber::factory()->create(['salary_percent' => 40, 'price' => 100000]);
    $client = Client::factory()->create();

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(12, 0),
        'ends_at' => now()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 150000,
        'payment_type' => 'card',
    ]);

    $instance = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->set('month', '2026-06')
        ->instance();

    $stat = $instance->monthlyBarberStats()->firstWhere('id', $barber->id);

    // выручка 150000, ЗП 40% = 60000, остаток = 90000
    expect($stat->revenue)->toBe(150000)
        ->and($stat->salary)->toBe(60000)
        ->and($stat->remainder)->toBe(90000)
        ->and((int) $instance->monthlyBarberStats()->sum('remainder'))->toBe(90000);

    Carbon::setTestNow();
});
