<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function navAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

function navBarberUser(): array
{
    $user = User::factory()->create(['role' => Role::BARBER]);
    $barber = Barber::factory()->create(['user_id' => $user->id]);

    return [$user, $barber];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-07 10:00:00', 'Asia/Tashkent'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Date picker (#64)
|--------------------------------------------------------------------------
*/

it('keeps the appointments list, header and form date in sync when the date input changes', function () {
    $barber = Barber::factory()->create();

    Appointment::create([
        'client_id' => Client::factory()->create(['name' => 'August Tenth Client'])->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-10 10:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-10 11:00:00', 'Asia/Tashkent'),
    ]);

    Appointment::create([
        'client_id' => Client::factory()->create(['name' => 'August Seventh Client'])->id,
        'barber_id' => $barber->id,
        'starts_at' => Carbon::parse('2026-08-07 10:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-07 11:00:00', 'Asia/Tashkent'),
    ]);

    Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->assertSee('August Seventh Client')
        ->assertDontSee('August Tenth Client')
        ->set('date', '2026-08-10')
        ->assertSet('date', '2026-08-10')
        ->assertSet('form_date', '2026-08-10')
        ->assertSee('August Tenth Client')
        ->assertDontSee('August Seventh Client')
        ->assertSee(Carbon::parse('2026-08-10')->translatedFormat('d F Y'));
});

it('falls back to today without a 500 when the date is cleared', function () {
    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->set('date', '');

    expect($component->get('date'))->toBe('2026-08-07')
        ->and($component->get('form_date'))->toBe('2026-08-07');
});

it('falls back to today without a 500 when the date is syntactically valid but impossible', function () {
    // Carbon::parse('0000-00-00') не кидает исключение и молча уезжает в
    // 1969-й/отрицательный год — только checkdate() ловит эту дату.
    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->set('date', '0000-00-00');

    expect($component->get('date'))->toBe('2026-08-07')
        ->and($component->get('form_date'))->toBe('2026-08-07');
});

it('falls back to today without a 500 when the date is garbage text', function () {
    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->set('date', 'not-a-date');

    expect($component->get('date'))->toBe('2026-08-07')
        ->and($component->get('form_date'))->toBe('2026-08-07');
});

it('highlights "today" only when the selected day really is today', function () {
    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments');

    expect($component->instance()->isToday)->toBeTrue();

    $component->set('date', '2026-08-10');

    expect($component->instance()->isToday)->toBeFalse();

    $component->call('setDate', Carbon::now('Asia/Tashkent')->toDateString());

    expect($component->instance()->isToday)->toBeTrue()
        ->and($component->get('date'))->toBe('2026-08-07');
});

/*
|--------------------------------------------------------------------------
| timeSlots() and openCreate() defaults (#63)
|--------------------------------------------------------------------------
*/

it('keeps the auto-computed end time selectable even off the step grid', function () {
    // Шаг сетки по умолчанию — час, а популярная услуга идёт 45 минут:
    // 10:00 + 45 мин = 10:45, которого нет среди часовых опций.
    $service = Service::factory()->create(['duration_minutes' => 45]);

    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->set('selectedServices', [['service_id' => $service->id, 'amount' => null]])
        ->set('form_start_time', '10:00');

    expect($component->get('form_end_time'))->toBe('10:45')
        ->and($component->instance()->timeSlots)->toContain('10:45')
        ->and($component->instance()->timeSlots)->toContain('10:00');
});

it('seeds the barber from an active filter and a sensible start time when opening a new appointment', function () {
    $filtered = Barber::factory()->create(['name' => 'Filtered Barber']);
    Barber::factory()->create(['name' => 'Other Barber']);

    // 10:15 -> следующая часовая граница при шаге 60 — 11:00.
    Carbon::setTestNow(Carbon::parse('2026-08-07 10:15:00', 'Asia/Tashkent'));

    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->set('barberFilter', $filtered->id)
        ->call('openCreate');

    expect($component->get('barber_id'))->toBe($filtered->id)
        ->and($component->get('form_start_time'))->toBe('11:00')
        ->and($component->get('showForm'))->toBeTrue();
});

it('seeds nothing for the barber when no filter is active', function () {
    Barber::factory()->create();

    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate');

    expect($component->get('barber_id'))->toBeNull();
});

it('suggests the first slot of the day for a new appointment on a future day', function () {
    Barber::factory()->create();

    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->set('date', '2026-08-10')
        ->call('openCreate');

    expect($component->get('form_start_time'))->toBe('00:00');
});

/*
|--------------------------------------------------------------------------
| Status actions and their guard rails (#62)
|--------------------------------------------------------------------------
*/

function statusAppointment(Barber $barber, AppointmentStatus $status): Appointment
{
    return Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'status' => $status,
        'starts_at' => Carbon::parse('2026-08-07 10:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-07 11:00:00', 'Asia/Tashkent'),
    ]);
}

it('still completes and cancels appointments', function () {
    $barber = Barber::factory()->create();
    $toComplete = statusAppointment($barber, AppointmentStatus::Confirmed);
    $toCancel = statusAppointment($barber, AppointmentStatus::Pending);

    Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->call('markCompleted', $toComplete->id)
        ->call('markCancelled', $toCancel->id);

    expect($toComplete->fresh()->status)->toBe(AppointmentStatus::Completed)
        ->and($toCancel->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('returns a completed appointment back to confirmed through markConfirmed', function () {
    $barber = Barber::factory()->create();
    $completed = statusAppointment($barber, AppointmentStatus::Completed);

    Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->call('markConfirmed', $completed->id);

    expect($completed->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

// Issue #74: a barber may change the status of THEIR OWN appointments — the
// blanket ban is gone, replaced by an ownership guard derived from the session.
it('lets a barber complete and cancel their own appointment', function () {
    [$user, $barber] = navBarberUser();
    $toComplete = statusAppointment($barber, AppointmentStatus::Confirmed);
    $toCancel = statusAppointment($barber, AppointmentStatus::Pending);

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('markCompleted', $toComplete->id)
        ->assertOk()
        ->call('markCancelled', $toCancel->id)
        ->assertOk();

    expect($toComplete->fresh()->status)->toBe(AppointmentStatus::Completed)
        ->and($toCancel->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('forbids a barber from changing the status of another barber appointment', function () {
    [$user] = navBarberUser();
    $other = Barber::factory()->create();
    $appointment = statusAppointment($other, AppointmentStatus::Confirmed);

    foreach (['markCompleted', 'markCancelled', 'markConfirmed'] as $method) {
        Livewire::actingAs($user)
            ->test('pages.admin.appointments')
            ->call($method, $appointment->id)
            ->assertForbidden();
    }

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('forbids a barber with no linked profile from changing any appointment status', function () {
    // Ни своих записей у него нет, ни исключений из ownership-guard.
    $user = User::factory()->create(['role' => Role::BARBER]);
    $barber = Barber::factory()->create();
    $appointment = statusAppointment($barber, AppointmentStatus::Confirmed);

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('markCompleted', $appointment->id)
        ->assertForbidden();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});
