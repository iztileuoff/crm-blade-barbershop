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
