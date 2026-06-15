<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('allows booking a time slot that already has an appointment', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    // An existing appointment already occupies 10:00–11:00.
    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'starts_at' => $date.' 10:00:00',
        'ends_at' => $date.' 11:00:00',
        'status' => AppointmentStatus::Pending,
    ]);

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->call('selectTime', '10:00')
        ->set('name', 'Тест Клиент')
        ->set('phone', '998901234567')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    // The overlapping slot was booked anyway — now two appointments at 10:00.
    expect(Appointment::where('barber_id', $barber->id)->count())->toBe(2);
});
