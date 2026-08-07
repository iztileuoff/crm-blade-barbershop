<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Jobs\NotifyAppointmentClientOfCancellation;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\SmsService;
use App\Services\TelegramService;
use App\Support\NotificationTemplates;
use App\Telegram\AppointmentNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function linkedBarber(int $chatId): Barber
{
    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => $chatId]);

    return Barber::factory()->create(['user_id' => $user->id]);
}

function makeAppointment(Barber $barber, Client $client, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    return Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->addDay()->setTime(12, 0),
        'ends_at' => now()->addDay()->setTime(13, 0),
        'status' => $status,
        'price' => 50000,
    ]);
}

it('notifies the barber when a new appointment is created', function () {
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create();

    $appointment = makeAppointment($barber, $client);

    Queue::assertPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::NewForBarber,
    );
});

it('does not notify when the barber has no linked telegram', function () {
    Queue::fake();
    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => null]);
    $barber = Barber::factory()->create(['user_id' => $user->id]);

    makeAppointment($barber, Client::factory()->create());

    Queue::assertNotPushed(SendAppointmentNotification::class);
});

it('notifies a telegram-linked client when a new appointment is created', function () {
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);

    makeAppointment($barber, $client, AppointmentStatus::Pending);

    Queue::assertPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::NewForClient,
    );
});

it('tells a client their appointment is confirmed when the salon books it as confirmed', function () {
    // Админ заводит запись сразу подтверждённой, и статус больше не меняется:
    // «мы свяжемся для подтверждения» было бы единственным и неверным сообщением.
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);

    makeAppointment($barber, $client, AppointmentStatus::Confirmed);

    Queue::assertPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::ConfirmedForClient,
    );

    Queue::assertNotPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::NewForClient,
    );
});

it('notifies the client and the barber exactly once each, not more, when both are telegram-linked', function () {
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);

    makeAppointment($barber, $client);

    expect(Queue::pushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::NewForBarber,
    ))->toHaveCount(1);

    expect(Queue::pushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::ConfirmedForClient,
    ))->toHaveCount(1);
});

it('does not notify the client on creation when telegram is not linked', function () {
    // Item 1 in #76 is Telegram-only for new/confirmed — no SMS fallback there,
    // unlike cancellation.
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => null]);

    makeAppointment($barber, $client);

    Queue::assertNotPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::NewForClient,
    );
});

it('notifies a telegram-linked client when the appointment is confirmed', function () {
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);
    $appointment = makeAppointment($barber, $client, AppointmentStatus::Pending);

    $appointment->update(['status' => AppointmentStatus::Confirmed]);

    Queue::assertPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::ConfirmedForClient,
    );
});

it('notifies barber and dispatches the client cancellation job when an appointment is cancelled', function () {
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);
    $appointment = makeAppointment($barber, $client);

    $appointment->update(['status' => AppointmentStatus::Cancelled]);

    Queue::assertPushed(SendAppointmentNotification::class, fn ($job) => (fn () => $this->notice)->call($job) === AppointmentNotice::CancelledForBarber);
    Queue::assertPushed(
        NotifyAppointmentClientOfCancellation::class,
        fn (NotifyAppointmentClientOfCancellation $job) => (fn () => $this->appointmentId)->call($job) === $appointment->id,
    );
});

it('cancellation reaches a telegram-linked client via telegram, not sms', function () {
    // No linked barber here: isolates the assertion to the client-side ladder,
    // which is what NotifyAppointmentClientOfCancellation is responsible for.
    $unlinkedBarberUser = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => null]);
    $barber = Barber::factory()->create(['user_id' => $unlinkedBarberUser->id]);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);
    $appointment = makeAppointment($barber, $client);

    $this->mock(TelegramService::class)
        ->shouldReceive('sendMessage')->once()->with(456, Mockery::type('string'))->andReturnTrue();
    $this->mock(SmsService::class)
        ->shouldNotReceive('sendSms');

    $appointment->update(['status' => AppointmentStatus::Cancelled]);
});

it('cancellation falls back to sms when the client has no linked telegram', function () {
    $unlinkedBarberUser = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => null]);
    $barber = Barber::factory()->create(['user_id' => $unlinkedBarberUser->id]);
    $client = Client::factory()->create(['telegram_chat_id' => null, 'phone' => '998901234567']);
    $appointment = makeAppointment($barber, $client);

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')->once()->with('998901234567', Mockery::type('string'), $client->id, 'cancelled')->andReturnTrue();
    $this->mock(TelegramService::class)
        ->shouldNotReceive('sendMessage');

    $appointment->update(['status' => AppointmentStatus::Cancelled]);
});

it('sends the cancellation sms to a client without telegram in their own stored locale', function () {
    $unlinkedBarberUser = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => null]);
    $barber = Barber::factory()->create(['user_id' => $unlinkedBarberUser->id]);
    $client = Client::factory()->create([
        'telegram_chat_id' => null,
        'phone' => '998901234567',
        'locale' => 'uz',
    ]);
    $appointment = makeAppointment($barber, $client);

    $expectedText = NotificationTemplates::renderSms('cancelled', [
        'time' => $appointment->starts_at->format('H:i'),
        'date' => Client::formatRussianDate($appointment->starts_at),
    ], 'uz');

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')->once()->with('998901234567', $expectedText, $client->id, 'cancelled')->andReturnTrue();
    $this->mock(TelegramService::class)
        ->shouldNotReceive('sendMessage');

    $appointment->update(['status' => AppointmentStatus::Cancelled]);
});

it('falls back to the global sms_locale for the cancellation sms when the client has no stored locale', function () {
    Setting::set('sms_locale', 'kaa');

    $unlinkedBarberUser = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => null]);
    $barber = Barber::factory()->create(['user_id' => $unlinkedBarberUser->id]);
    $client = Client::factory()->create([
        'telegram_chat_id' => null,
        'phone' => '998901234567',
        'locale' => null,
    ]);
    $appointment = makeAppointment($barber, $client);

    $expectedText = NotificationTemplates::renderSms('cancelled', [
        'time' => $appointment->starts_at->format('H:i'),
        'date' => Client::formatRussianDate($appointment->starts_at),
    ], 'kaa');

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')->once()->with('998901234567', $expectedText, $client->id, 'cancelled')->andReturnTrue();
    $this->mock(TelegramService::class)
        ->shouldNotReceive('sendMessage');

    $appointment->update(['status' => AppointmentStatus::Cancelled]);
});

it('does not tell the client the appointment is confirmed when the status is walked back from completed', function () {
    // Возврат из «завершена» — исправление статуса задним числом: клиент о
    // завершении и не знал. Уведомлять его тут не о чем.
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);
    $appointment = makeAppointment($barber, $client, AppointmentStatus::Completed);

    $appointment->update(['status' => AppointmentStatus::Confirmed]);

    Queue::assertNotPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::ConfirmedForClient,
    );
});

it('cannot be made to spam the client by flipping completed and confirmed back and forth', function () {
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);
    $appointment = makeAppointment($barber, $client, AppointmentStatus::Confirmed);

    Queue::fake();

    foreach (range(1, 3) as $ignored) {
        $appointment->update(['status' => AppointmentStatus::Completed]);
        $appointment->update(['status' => AppointmentStatus::Confirmed]);
    }

    expect(Queue::pushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::ConfirmedForClient,
    ))->toHaveCount(0);
});

it('still tells the client when a cancelled appointment is brought back to confirmed', function () {
    // Про отмену клиенту сообщили — возврат для него настоящая новость.
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);
    $appointment = makeAppointment($barber, $client, AppointmentStatus::Cancelled);

    Queue::fake();
    $appointment->update(['status' => AppointmentStatus::Confirmed]);

    Queue::assertPushed(
        SendAppointmentNotification::class,
        fn (SendAppointmentNotification $job) => (fn () => $this->notice)->call($job) === AppointmentNotice::ConfirmedForClient,
    );
});

it('does not re-notify on unrelated updates', function () {
    $barber = linkedBarber(123);
    $appointment = makeAppointment($barber, Client::factory()->create());

    Queue::fake();
    $appointment->update(['price' => 60000]);

    Queue::assertNotPushed(SendAppointmentNotification::class);
});
