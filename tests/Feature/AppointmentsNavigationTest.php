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

// Issue #77: the day summary (count/sum/debt) is read straight off the
// already-loaded $this->appointments collection — no second query — so it
// needs its own coverage that the arithmetic (and the cancelled/other-day
// exclusions) actually lands on the numbers the header renders.
it('shows the right count, sum and debt in the day summary for a seeded day', function () {
    $barber = Barber::factory()->create();

    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'status' => AppointmentStatus::Completed,
        'starts_at' => Carbon::parse('2026-08-10 10:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-10 11:00:00', 'Asia/Tashkent'),
        'price' => 30000,
    ]);

    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => Carbon::parse('2026-08-10 12:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-10 13:00:00', 'Asia/Tashkent'),
        'price' => 20000,
        'debt_amount' => 5000,
    ]);

    // Cancelled: still counted in "how many today", but excluded from the sum
    // and the debt — it never booked real revenue.
    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'status' => AppointmentStatus::Cancelled,
        'starts_at' => Carbon::parse('2026-08-10 15:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-10 16:00:00', 'Asia/Tashkent'),
        'price' => 100000,
        'debt_amount' => 100000,
    ]);

    // A different day entirely — must not leak into the summary.
    Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'status' => AppointmentStatus::Completed,
        'starts_at' => Carbon::parse('2026-08-11 10:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-11 11:00:00', 'Asia/Tashkent'),
        'price' => 999999,
    ]);

    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->set('date', '2026-08-10');

    expect($component->instance()->daySummary)->toBe([
        'count' => 3,
        'total' => 50000,
        'debt' => 5000,
    ]);

    $currency = __('common.currency');

    $component
        ->assertSee(__('appointments.day_summary_count', ['count' => 3]))
        ->assertSee(__('appointments.day_summary_total', ['amount' => '50 000 '.$currency]))
        ->assertSee(__('appointments.day_summary_debt', ['amount' => '5 000 '.$currency]));
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

it('derives the end time when the service is picked, not only when the start is typed', function () {
    // openCreate() подставляет начало присваиванием, а от присваивания
    // updated-хук не срабатывает: если конец не вывести на выборе услуги, он
    // так и останется пустым до самого save().
    $service = Service::factory()->create(['duration_minutes' => 45]);
    Carbon::setTestNow(Carbon::parse('2026-08-07 10:15:00', 'Asia/Tashkent'));

    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->call('addService')
        ->set('selectedServices.0.service_id', $service->id);

    expect($component->get('form_start_time'))->toBe('11:00')
        ->and($component->get('form_end_time'))->toBe('11:45');
});

it('saves a new appointment without the admin ever touching the end time', function () {
    $barber = Barber::factory()->create();
    $service = Service::factory()->create(['duration_minutes' => 60]);
    Carbon::setTestNow(Carbon::parse('2026-08-07 10:15:00', 'Asia/Tashkent'));

    Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->set('barber_id', $barber->id)
        ->set('client_id', Client::factory()->create()->id)
        ->call('addService')
        ->set('selectedServices.0.service_id', $service->id)
        ->call('save')
        ->assertHasNoErrors();

    $appointment = Appointment::firstOrFail();

    expect($appointment->starts_at->format('H:i'))->toBe('11:00')
        ->and($appointment->ends_at->format('H:i'))->toBe('12:00');
});

it('does not overwrite an end time the admin set by hand when a later service changes', function () {
    $first = Service::factory()->create(['duration_minutes' => 30]);
    $second = Service::factory()->create(['duration_minutes' => 30]);

    $component = Livewire::actingAs(navAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->call('addService')
        ->set('selectedServices.0.service_id', $first->id)
        ->set('form_end_time', '13:30')
        ->call('addService')
        ->set('selectedServices.1.service_id', $second->id);

    expect($component->get('form_end_time'))->toBe('13:30');
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
