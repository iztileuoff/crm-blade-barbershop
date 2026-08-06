<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use App\Telegram\Keyboards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;

uses(RefreshDatabase::class);

/**
 * Достаём три суммы из ответа бота «Заработок»: Сегодня / За неделю / За месяц.
 * Тот же хелпер, что и в BarberEarningsTelegramTest — фигуры должны сойтись
 * ровно, обе точки входа зовут App\Support\BarberEarnings::periodShare().
 *
 * @return array{today: int, week: int, month: int}
 */
function crmEarningsFigures(Nutgram $bot): array
{
    $request = $bot->getRequestHistory()[0]['request'] ?? null;
    $text = $request === null ? '' : (string) $request->getBody();

    preg_match('/Сегодня: <b>([\d\s]+)/u', $text, $today);
    preg_match('/За неделю: <b>([\d\s]+)/u', $text, $week);
    preg_match('/За месяц: <b>([\d\s]+)/u', $text, $month);

    $toInt = fn (array $m) => (int) preg_replace('/\D/', '', $m[1] ?? '0');

    return [
        'today' => $toInt($today),
        'week' => $toInt($week),
        'month' => $toInt($month),
    ];
}

it('reports the same figures as the Telegram bot for a barber', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'));

    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 556001]);
    $barber = Barber::factory()->create(['user_id' => $user->id, 'salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-12 09:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
    ]);

    $bot = Nutgram::fake();
    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();
    $bot->setCommonChat(Chat::make(id: 556001, type: ChatType::PRIVATE));
    $bot->hearText(Keyboards::BARBER_EARNINGS)->reply();

    $botFigures = crmEarningsFigures($bot);

    $pageEarnings = Livewire::actingAs($user)
        ->test('pages.admin.earnings')
        ->instance()
        ->earnings();

    expect($pageEarnings)->toBe($botFigures)
        ->and($pageEarnings['today'])->toBe(50000);

    Carbon::setTestNow();
});

it('books a debt repayment on the CRM earnings page the day it was collected, same as the bot', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'));

    $admin = User::factory()->create(['role' => Role::ADMIN]);
    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 556002]);
    $barber = Barber::factory()->create(['user_id' => $user->id, 'salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    // Визит на прошлой неделе, целиком в долг.
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-05 12:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-05 13:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
        'debt_amount' => 100000,
    ]);

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 100000)
        ->call('payAppointmentDebt');

    $pageEarnings = Livewire::actingAs($user)
        ->test('pages.admin.earnings')
        ->instance()
        ->earnings();

    // Деньги приняли сегодня — доля видна и в «Сегодня», и в старших периодах.
    expect($pageEarnings['today'])->toBe(50000)
        ->and($pageEarnings['week'])->toBe(50000)
        ->and($pageEarnings['month'])->toBe(50000);

    Carbon::setTestNow();
});

it('shows a barber only their own earnings figures, never leaking another barber own numbers', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'));

    $user = User::factory()->create(['role' => Role::BARBER]);
    $barber = Barber::factory()->create(['user_id' => $user->id, 'salary_percent' => 50, 'price' => 100000]);

    // Другой мастер, отработавший в тот же день на куда большую сумму — она не
    // должна попасть на страницу первого мастера ни в одном из трёх периодов.
    $otherBarber = Barber::factory()->create(['salary_percent' => 90, 'price' => 300000]);

    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-12 09:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
    ]);

    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $otherBarber->id,
        'starts_at' => Carbon::parse('2026-08-12 09:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 300000,
        'payment_type' => 'cash',
    ]);

    $earnings = Livewire::actingAs($user)
        ->test('pages.admin.earnings')
        ->instance()
        ->earnings();

    // 50% от собственных 100000, а не 90% от чужих 300000.
    expect($earnings)->toBe(['today' => 50000, 'week' => 50000, 'month' => 50000]);

    Carbon::setTestNow();
});

it('matches the Telegram bot exactly in one scenario mixing a completed visit, a debt, and a later repayment', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05 09:00:00', 'Asia/Tashkent'));

    $admin = User::factory()->create(['role' => Role::ADMIN]);
    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 556009]);
    $barber = Barber::factory()->create(['user_id' => $user->id, 'salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    // Визит: половину приняли наличными на месте, половину — в долг.
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-05 09:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-05 10:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'both',
        'cash_amount' => 40000,
        'card_amount' => 0,
        'debt_amount' => 60000,
    ]);

    // Погашение — только через три дня, но всё ещё внутри той же недели.
    Carbon::setTestNow(Carbon::parse('2026-08-08 12:00:00', 'Asia/Tashkent'));

    Livewire::actingAs($admin)
        ->test('pages.admin.debts')
        ->call('openPayAppointment', $appointment->id)
        ->set('payAmount', 60000)
        ->call('payAppointmentDebt');

    $pageEarnings = Livewire::actingAs($user)
        ->test('pages.admin.earnings')
        ->instance()
        ->earnings();

    $bot = Nutgram::fake();
    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();
    $bot->setCommonChat(Chat::make(id: 556009, type: ChatType::PRIVATE));
    $bot->hearText(Keyboards::BARBER_EARNINGS)->reply();

    $botFigures = crmEarningsFigures($bot);

    expect($pageEarnings)->toBe($botFigures)
        // Сегодня (8 августа): только доля с погашения — 50% от 60000.
        ->and($pageEarnings['today'])->toBe(30000)
        // За неделю (3-9 августа) и за месяц: доля с визита (50% от 40000 нала
        // на дне визита) плюс доля с погашения — обе даты внутри периода.
        ->and($pageEarnings['week'])->toBe(50000)
        ->and($pageEarnings['month'])->toBe(50000);

    Carbon::setTestNow();
});

it('shows a barber with no linked profile an explicit empty state, not someone else earnings', function () {
    $user = User::factory()->create(['role' => Role::BARBER]);
    $otherBarber = Barber::factory()->create(['salary_percent' => 90, 'price' => 200000]);

    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $otherBarber->id,
        'starts_at' => now()->setTime(9, 0),
        'ends_at' => now()->setTime(10, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 200000,
        'payment_type' => 'cash',
    ]);

    Livewire::actingAs($user)
        ->test('pages.admin.earnings')
        ->assertOk()
        ->assertSee(__('earnings.no_profile_barber'))
        ->assertDontSee($otherBarber->name);
});

it('shows an admin with no linked barber profile a distinct empty state', function () {
    $admin = User::factory()->create(['role' => Role::ADMIN]);

    Livewire::actingAs($admin)
        ->test('pages.admin.earnings')
        ->assertOk()
        ->assertSee(__('earnings.no_profile_admin'));
});

it('shows an admin their own earnings when a barber profile is linked to their account', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'));

    $admin = User::factory()->create(['role' => Role::ADMIN]);
    $barber = Barber::factory()->create(['user_id' => $admin->id, 'salary_percent' => 50, 'price' => 100000, 'name' => 'Owner Barber']);
    $client = Client::factory()->create();

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-12 09:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
    ]);

    Livewire::actingAs($admin)
        ->test('pages.admin.earnings')
        ->assertOk()
        ->assertSee('Owner Barber')
        ->assertDontSee(__('earnings.no_profile_admin'));

    Carbon::setTestNow();
});

it('lets a barber reach the earnings page without being redirected', function () {
    $user = User::factory()->create(['role' => Role::BARBER]);
    Barber::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('admin.earnings'))
        ->assertOk();
});
