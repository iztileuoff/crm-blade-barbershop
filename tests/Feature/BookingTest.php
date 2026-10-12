<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('books a second appointment into a time slot that already has one', function () {
    // Салон сам разводит двух клиентов одного часа на странице записей (issue #98).
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

    expect(Appointment::where('barber_id', $barber->id)->count())->toBe(2);
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

// Issue #77: a taken slot must not rely on colour alone to say so — it needs a
// text cue. Issue #98: it stays clickable, the mark is a warning, not a lock.
it('marks a taken booking slot with a text cue, not colour alone, and keeps it clickable', function () {
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

    $html = Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->html();

    // The taken 10:00 button: an aria-label/title carrying the "taken" copy,
    // plus a visible text label under the time — not just a colour class a
    // colour-blind or screen-reader user could never perceive.
    expect($html)
        ->toContain('wire:click="selectTime(\'10:00\')"')
        ->toContain(__('booking.datetime.taken_bookable'))
        ->toContain(__('booking.datetime.taken_short'));

    preg_match('/<button[^>]*wire:click="selectTime\(\'10:00\'\)"[^>]*>/s', $html, $matches);
    expect($matches)->not->toBeEmpty('Could not find the 10:00 slot button in the rendered markup.');

    $button = $matches[0];
    expect($button)->not->toContain('disabled')
        ->and($button)->toContain('aria-label="')
        ->and($button)->toContain(__('booking.datetime.taken_bookable'));
});
