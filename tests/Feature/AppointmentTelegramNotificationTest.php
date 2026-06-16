<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
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

it('notifies barber and client when an appointment is cancelled', function () {
    Queue::fake();
    $barber = linkedBarber(123);
    $client = Client::factory()->create(['telegram_chat_id' => 456]);
    $appointment = makeAppointment($barber, $client);

    $appointment->update(['status' => AppointmentStatus::Cancelled]);

    Queue::assertPushed(SendAppointmentNotification::class, fn ($job) => (fn () => $this->notice)->call($job) === AppointmentNotice::CancelledForBarber);
    Queue::assertPushed(SendAppointmentNotification::class, fn ($job) => (fn () => $this->notice)->call($job) === AppointmentNotice::CancelledForClient);
});

it('does not re-notify on unrelated updates', function () {
    $barber = linkedBarber(123);
    $appointment = makeAppointment($barber, Client::factory()->create());

    Queue::fake();
    $appointment->update(['price' => 60000]);

    Queue::assertNotPushed(SendAppointmentNotification::class);
});
