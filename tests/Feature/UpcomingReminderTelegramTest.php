<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use App\Services\SmsService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function appointmentInWindow(Barber $barber, Client $client): Appointment
{
    return Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->addMinutes(30),
        'ends_at' => now()->addMinutes(90),
        'status' => AppointmentStatus::Confirmed,
        'price' => 50000,
        'notified_30min' => false,
    ]);
}

it('reminds a linked client via telegram and skips sms', function () {
    Queue::fake();

    $barberUser = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 999]);
    $barber = Barber::factory()->create(['user_id' => $barberUser->id]);
    $client = Client::factory()->create(['telegram_chat_id' => 111]);
    $appointment = appointmentInWindow($barber, $client);

    $this->mock(TelegramService::class)
        ->shouldReceive('sendMessage')->andReturnTrue();
    $this->mock(SmsService::class)
        ->shouldNotReceive('sendSms');

    $this->artisan('app:send-upcoming-reminders')->assertSuccessful();

    expect($appointment->fresh()->notified_30min)->toBeTrue();
});

it('falls back to sms when the client has no telegram', function () {
    Queue::fake();

    $barberUser = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => null]);
    $barber = Barber::factory()->create(['user_id' => $barberUser->id]);
    $client = Client::factory()->create(['telegram_chat_id' => null, 'phone' => '998901234567']);
    $appointment = appointmentInWindow($barber, $client);

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')->once()->andReturnTrue();
    $this->mock(TelegramService::class)
        ->shouldNotReceive('sendMessage');

    $this->artisan('app:send-upcoming-reminders')->assertSuccessful();

    expect($appointment->fresh()->notified_30min)->toBeTrue();
});
