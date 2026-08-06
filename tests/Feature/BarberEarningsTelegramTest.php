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
 * Достаём три суммы из ответа «Заработок»: Сегодня / За неделю / За месяц.
 *
 * @return array{today: int, week: int, month: int}
 */
function earningsFigures(Nutgram $bot): array
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

it('counts an appointment completed for later today in all three periods', function () {
    // Среда, чтобы «сегодня» гарантированно лежало внутри текущей недели и месяца.
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'));

    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 555001]);
    $barber = Barber::factory()->create(['user_id' => $user->id, 'salary_percent' => 50, 'price' => 100000]);
    $client = Client::factory()->create();

    // Визит стоит на 20:00 — позже «сейчас», но администратор уже закрыл его.
    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-12 20:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-12 21:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 100000,
        'payment_type' => 'cash',
    ]);

    $bot = Nutgram::fake();
    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();
    $bot->setCommonChat(Chat::make(id: 555001, type: ChatType::PRIVATE));

    $bot->hearText(Keyboards::BARBER_EARNINGS)->reply();

    $figures = earningsFigures($bot);

    expect($figures['today'])->toBe(50000)
        ->and($figures['week'])->toBe(50000)
        ->and($figures['month'])->toBe(50000);

    Carbon::setTestNow();
});

it('reports the same figure as the dashboard for a visit that went on credit', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'));

    $admin = User::factory()->create(['role' => Role::ADMIN]);
    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 555003]);
    $barber = Barber::factory()->create(['user_id' => $user->id, 'salary_percent' => 100, 'price' => 120000]);
    $client = Client::factory()->create();

    // Запись #1270 из дампа: услуга целиком ушла в долг — в кассу не пришло ничего.
    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-12 09:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'),
        'status' => AppointmentStatus::Completed,
        'price' => 120000,
        'payment_type' => 'cash',
        'debt_amount' => 120000,
    ]);

    $dashboardSalary = Livewire::actingAs($admin)
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'day')
        ->set('date', '2026-08-12')
        ->instance()
        ->barberStats()
        ->firstWhere('id', $barber->id)
        ->salary;

    $bot = Nutgram::fake();
    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();
    $bot->setCommonChat(Chat::make(id: 555003, type: ChatType::PRIVATE));
    $bot->hearText(Keyboards::BARBER_EARNINGS)->reply();

    expect(earningsFigures($bot)['today'])->toBe($dashboardSalary)
        ->and($dashboardSalary)->toBe(0);

    Carbon::setTestNow();
});

it('never reports more for today than for the week or the month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Tashkent'));

    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 555002]);
    $barber = Barber::factory()->create(['user_id' => $user->id, 'salary_percent' => 60, 'price' => 100000]);
    $client = Client::factory()->create();

    foreach (['2026-08-12 21:00:00', '2026-08-12 08:00:00', '2026-08-10 09:00:00'] as $startsAt) {
        Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'starts_at' => Carbon::parse($startsAt, 'Asia/Tashkent'),
            'ends_at' => Carbon::parse($startsAt, 'Asia/Tashkent')->addHour(),
            'status' => AppointmentStatus::Completed,
            'price' => 100000,
            'payment_type' => 'cash',
        ]);
    }

    $bot = Nutgram::fake();
    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();
    $bot->setCommonChat(Chat::make(id: 555002, type: ChatType::PRIVATE));

    $bot->hearText(Keyboards::BARBER_EARNINGS)->reply();

    $figures = earningsFigures($bot);

    expect($figures['today'])->toBeLessThanOrEqual($figures['week'])
        ->and($figures['week'])->toBeLessThanOrEqual($figures['month'])
        // сегодня 2 записи по 60%, за неделю и месяц — все 3
        ->and($figures['today'])->toBe(120000)
        ->and($figures['week'])->toBe(180000)
        ->and($figures['month'])->toBe(180000);

    Carbon::setTestNow();
});
