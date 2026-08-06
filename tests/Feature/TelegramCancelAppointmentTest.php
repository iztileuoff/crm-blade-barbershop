<?php

use App\Enums\AppointmentStatus;
use App\Jobs\NotifyAppointmentClientOfCancellation;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;

uses(RefreshDatabase::class);

function cancelFlowBot(): Nutgram
{
    $bot = Nutgram::fake();

    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();

    return $bot;
}

function cancelFlowChat(int $chatId): Chat
{
    return Chat::make(id: $chatId, type: ChatType::PRIVATE);
}

function cancelFlowAppointment(Client $client, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    $barber = Barber::factory()->create();

    return Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->addDay()->setTime(12, 0),
        'ends_at' => now()->addDay()->setTime(13, 0),
        'status' => $status,
        'price' => 50000,
    ]);
}

it('offers a cancel button under every upcoming appointment', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 777]);
    $appointment = cancelFlowAppointment($client);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(777));

    $bot->hearText(__('telegram.kb_appointments'))->reply();

    // Index 0 is the header message; index 1 is the per-appointment message
    // carrying the inline "cancel" button.
    $bot->assertRaw(
        fn ($request) => str_contains((string) $request->getBody(), "cancel:{$appointment->id}"),
        index: 1,
    );
});

it('lets a linked client cancel their own upcoming appointment', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 777]);
    $appointment = cancelFlowAppointment($client);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(777));

    $bot->hearCallbackQueryData("cancel:{$appointment->id}")->reply();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    $bot->assertReply('answerCallbackQuery');
});

it('does not send a second cancellation notice to the client who cancelled it themselves', function () {
    // Клиент уже получил ответ в том же сообщении — отдельное «ваша запись
    // отменена» было бы шумом от его собственного действия.
    Queue::fake();

    $client = Client::factory()->create(['telegram_chat_id' => 777]);
    $appointment = cancelFlowAppointment($client);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(777));

    $bot->hearCallbackQueryData("cancel:{$appointment->id}")->reply();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    Queue::assertNotPushed(NotifyAppointmentClientOfCancellation::class);
});

it('never trusts the callback payload: refuses to cancel another client’s appointment', function () {
    $owner = Client::factory()->create(['telegram_chat_id' => 111]);
    $attacker = Client::factory()->create(['telegram_chat_id' => 222]);
    $appointment = cancelFlowAppointment($owner);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(222));

    $bot->hearCallbackQueryData("cancel:{$appointment->id}")->reply();

    expect($appointment->fresh()->status)->not->toBe(AppointmentStatus::Cancelled);
    $bot->assertReply('answerCallbackQuery');
});

it('refuses to re-cancel an already cancelled appointment', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 333]);
    $appointment = cancelFlowAppointment($client, AppointmentStatus::Cancelled);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(333));

    $bot->hearCallbackQueryData("cancel:{$appointment->id}")->reply();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('refuses to cancel an appointment that has already started', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 444]);
    $barber = Barber::factory()->create();
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addHour(),
        'status' => AppointmentStatus::Confirmed,
        'price' => 50000,
    ]);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(444));

    $bot->hearCallbackQueryData("cancel:{$appointment->id}")->reply();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('asks an unlinked chat to /start instead of cancelling anything', function () {
    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(999));

    $bot->hearCallbackQueryData('cancel:1')->reply();

    $bot->assertReply('answerCallbackQuery');
});

it('reports the outstanding debt amount to the client', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 666]);
    $barber = Barber::factory()->create();
    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addHour(),
        'status' => AppointmentStatus::Completed,
        'price' => 50000,
        'debt_amount' => 20000,
    ]);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(666));

    $bot->hearText(__('telegram.kb_debt'))->reply();

    $bot->assertReplyText(__('telegram.debt_amount', ['amount' => '20 000 '.__('common.currency')]));
});

it('replies to a client in their own stored locale, not the config default', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 555, 'locale' => 'uz']);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(555));

    $bot->hearText(trans('telegram.kb_debt', [], 'uz'))->reply();

    $bot->assertReplyText(__('telegram.no_debt', [], 'uz'));
});

it('falls back to the global sms_locale for a linked client with no stored locale', function () {
    Setting::set('sms_locale', 'kaa');
    $client = Client::factory()->create(['telegram_chat_id' => 888, 'locale' => null]);

    $bot = cancelFlowBot();
    $bot->setCommonChat(cancelFlowChat(888));

    $bot->hearText(__('telegram.kb_debt'))->reply();

    $bot->assertReplyText(__('telegram.no_debt', [], 'kaa'));
});
