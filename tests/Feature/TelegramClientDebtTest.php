<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\DebtPayment;
use App\Models\Order;
use App\Telegram\Keyboards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;

uses(RefreshDatabase::class);

/**
 * Текст ответа бота на кнопку «Долг».
 */
function debtReply(int $chatId): string
{
    $bot = Nutgram::fake();
    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();
    $bot->setCommonChat(Chat::make(id: $chatId, type: ChatType::PRIVATE));

    $bot->hearText(Keyboards::label(Keyboards::CLIENT_DEBT))->reply();

    $request = $bot->getRequestHistory()[0]['request'] ?? null;

    return $request === null ? '' : (string) $request->getBody();
}

/**
 * Сумма из ответа «Ваш долг: N сум», либо 0, если бот сказал «долга нет».
 */
function debtFigure(int $chatId): int
{
    $text = debtReply($chatId);

    preg_match('/Ваш долг:<\/b> ([\d\s]+)/u', $text, $m);

    return (int) preg_replace('/\D/', '', $m[1] ?? '0');
}

function debtorClient(int $chatId): Client
{
    return Client::factory()->create(['telegram_chat_id' => $chatId]);
}

function creditVisit(Client $client, int $debt): Appointment
{
    return Appointment::create([
        'client_id' => $client->id,
        'barber_id' => Barber::factory()->create()->id,
        'starts_at' => now()->subDay()->setTime(12, 0),
        'ends_at' => now()->subDay()->setTime(13, 0),
        'status' => AppointmentStatus::Completed,
        'price' => $debt,
        'payment_type' => 'cash',
        'debt_amount' => $debt,
    ]);
}

it('reports the issued debt while nothing has been repaid', function () {
    $client = debtorClient(770001);
    creditVisit($client, 200000);

    expect(debtFigure(770001))->toBe(200000);
});

it('says nothing is owed once the debt was accepted at the till', function () {
    // `debt_amount` при погашении не обнуляется — это выданный долг, а не
    // остаток. Раньше бот суммировал именно его и требовал деньги, которые
    // клиент уже принёс.
    $client = debtorClient(770002);
    $appointment = creditVisit($client, 200000);

    DebtPayment::factory()->create([
        'payable_type' => $appointment->getMorphClass(),
        'payable_id' => $appointment->id,
        'amount' => 200000,
    ]);

    expect(debtReply(770002))->toContain('нет задолженности');
});

it('reports only the part of the debt that is still outstanding', function () {
    $client = debtorClient(770003);
    $appointment = creditVisit($client, 200000);

    DebtPayment::factory()->create([
        'payable_type' => $appointment->getMorphClass(),
        'payable_id' => $appointment->id,
        'amount' => 120000,
    ]);

    expect(debtFigure(770003))->toBe(80000);
});

it('counts a product bought on credit, not just visits', function () {
    $client = debtorClient(770004);

    Order::create([
        'client_id' => $client->id,
        'total_price' => 90000,
        'payment_type' => 'cash',
        'debt_amount' => 90000,
    ]);

    expect(debtFigure(770004))->toBe(90000);
});

it('adds up visit and product debt into one figure', function () {
    $client = debtorClient(770005);
    creditVisit($client, 50000);

    Order::create([
        'client_id' => $client->id,
        'total_price' => 30000,
        'payment_type' => 'cash',
        'debt_amount' => 30000,
    ]);

    expect(debtFigure(770005))->toBe(80000);
});

it('does not leak another client\'s debt', function () {
    $client = debtorClient(770006);
    creditVisit(Client::factory()->create(), 500000);

    expect(debtReply(770006))->toContain('нет задолженности');
});

/*
|--------------------------------------------------------------------------
| «Мои записи»: потолок на число сообщений (#84)
|--------------------------------------------------------------------------
*/

/**
 * Сколько сообщений бот отправил в ответ на нажатие кнопки.
 *
 * @return array<int, string>
 */
function botReplies(int $chatId, string $button): array
{
    $bot = Nutgram::fake();
    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();
    $bot->setCommonChat(Chat::make(id: $chatId, type: ChatType::PRIVATE));

    $bot->hearText(Keyboards::label($button))->reply();

    return array_map(
        fn (array $entry) => (string) $entry['request']->getBody(),
        $bot->getRequestHistory(),
    );
}

function futureVisit(Client $client, int $daysAhead, ?Barber $barber = null): Appointment
{
    return Appointment::create([
        'client_id' => $client->id,
        'barber_id' => ($barber ?? Barber::factory()->create())->id,
        'starts_at' => now()->addDays($daysAhead)->setTime(12, 0),
        'ends_at' => now()->addDays($daysAhead)->setTime(13, 0),
        'status' => AppointmentStatus::Confirmed,
        'price' => 50000,
    ]);
}

it('sends one message per upcoming appointment while there are few of them', function () {
    $client = debtorClient(771001);
    foreach (range(1, 3) as $day) {
        futureVisit($client, $day);
    }

    // Заголовок + по сообщению на запись.
    expect(botReplies(771001, Keyboards::CLIENT_APPOINTMENTS))->toHaveCount(4);
});

it('caps the burst instead of walking into the telegram flood limit', function () {
    // Каждая запись уходит отдельным сообщением, а Telegram держит около
    // одного сообщения в секунду на чат: без потолка 25 записей — это 26
    // синхронных вызовов внутри одного вебхука.
    $client = debtorClient(771002);
    $barber = Barber::factory()->create();
    foreach (range(1, 25) as $day) {
        futureVisit($client, $day, $barber);
    }

    $replies = botReplies(771002, Keyboards::CLIENT_APPOINTMENTS);

    expect($replies)->toHaveCount(11);
});

it('says out loud that the list was cut, instead of passing it off as complete', function () {
    $client = debtorClient(771003);
    $barber = Barber::factory()->create();
    foreach (range(1, 25) as $day) {
        futureVisit($client, $day, $barber);
    }

    $header = botReplies(771003, Keyboards::CLIENT_APPOINTMENTS)[0];

    expect($header)->toContain('10')->toContain('25');
});

it('does not mention any cut when everything fits', function () {
    $client = debtorClient(771004);
    futureVisit($client, 1);

    $header = botReplies(771004, Keyboards::CLIENT_APPOINTMENTS)[0];

    expect($header)->not->toContain(__('telegram.upcoming_truncated', ['shown' => 1, 'total' => 1]));
});
