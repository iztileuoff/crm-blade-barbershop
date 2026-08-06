<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('refuses to book a time slot that already has an appointment', function () {
    // Утро: слот 10:00 сегодня ещё впереди, отказ будет именно из-за занятости.
    $this->travelTo(now()->startOfDay()->addHours(8));

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

    // Время выставляется напрямую — так же, как его подсунул бы клиент в обход
    // сетки слотов. Защита обязана держаться на сервере.
    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->set('time', '10:00')
        ->set('name', 'Тест Клиент')
        ->set('phone', '998901234567')
        ->call('confirm')
        ->assertHasErrors('time')
        ->assertSet('step', 3);

    expect(Appointment::where('barber_id', $barber->id)->count())->toBe(1);
});

it('books a free slot next to a taken one', function () {
    $this->travelTo(now()->startOfDay()->addHours(8));

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

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
        ->call('selectTime', '11:00')
        ->set('name', 'Тест Клиент')
        ->set('phone', '998901234567')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    expect(Appointment::where('barber_id', $barber->id)->count())->toBe(2);
});
