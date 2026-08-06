<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function scheduleAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

/**
 * A fully filled-in, always-valid week — every day open 09:00-20:00 — so a
 * test only has to override the one day it actually cares about.
 *
 * @return array<string, array{start: string, end: string, off: bool}>
 */
function fullWeekSchedule(): array
{
    return array_fill_keys(Barber::WEEKDAYS, ['start' => '09:00', 'end' => '20:00', 'off' => false]);
}

// --- Admin form: base price -------------------------------------------------

it('saves a base price through the admin form', function () {
    $specialization = Specialization::factory()->create();

    Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('openCreate')
        ->set('name', 'Тестовый Мастер')
        ->set('specialization_id', $specialization->id)
        ->set('price', 75000)
        ->set('schedule', fullWeekSchedule())
        ->call('save')
        ->assertHasNoErrors();

    expect(Barber::where('name', 'Тестовый Мастер')->first()->price)->toBe(75000);
});

// --- Admin form: save + reload round trip -----------------------------------

it('reloads the saved schedule and base price unchanged when the barber is reopened for editing', function () {
    $specialization = Specialization::factory()->create();
    $schedule = fullWeekSchedule();
    $schedule['mon'] = ['start' => '11:00', 'end' => '15:00', 'off' => false];
    $schedule['sun'] = ['start' => null, 'end' => null, 'off' => true];

    $component = Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('openCreate')
        ->set('name', 'Тестовый Мастер')
        ->set('specialization_id', $specialization->id)
        ->set('price', 65000)
        ->set('schedule', $schedule)
        ->call('save')
        ->assertHasNoErrors();

    $barber = Barber::where('name', 'Тестовый Мастер')->firstOrFail();

    // Re-opens the same barber for editing — a fresh read from the database,
    // not the in-memory $schedule the form just submitted — so this proves
    // the round trip through storage, not just that the property held its value.
    $component->call('edit', $barber->id)
        ->assertSet('price', 65000)
        ->assertSet('schedule.mon.start', '11:00')
        ->assertSet('schedule.mon.end', '15:00')
        ->assertSet('schedule.mon.off', false)
        ->assertSet('schedule.sun.off', true)
        ->assertSet('schedule.tue.start', '09:00')
        ->assertSet('schedule.tue.end', '20:00')
        ->assertSet('schedule.tue.off', false);
});

// --- Admin form: schedule validation ----------------------------------------

it('rejects a day whose end time is not after its start time', function () {
    $specialization = Specialization::factory()->create();
    $schedule = fullWeekSchedule();
    $schedule['mon']['start'] = '20:00';
    $schedule['mon']['end'] = '09:00';

    Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('openCreate')
        ->set('name', 'Тестовый Мастер')
        ->set('specialization_id', $specialization->id)
        ->set('schedule', $schedule)
        ->call('save')
        ->assertHasErrors('schedule.mon.end');

    expect(Barber::where('name', 'Тестовый Мастер')->exists())->toBeFalse();
});

it('rejects a malformed time in the schedule grid', function () {
    $specialization = Specialization::factory()->create();
    $schedule = fullWeekSchedule();
    $schedule['tue']['start'] = 'not-a-time';

    Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('openCreate')
        ->set('name', 'Тестовый Мастер')
        ->set('specialization_id', $specialization->id)
        ->set('schedule', $schedule)
        ->call('save')
        ->assertHasErrors('schedule.tue.start');
});

it('does not require start or end times for a day marked off', function () {
    $specialization = Specialization::factory()->create();
    $schedule = fullWeekSchedule();
    $schedule['sun'] = ['start' => null, 'end' => null, 'off' => true];

    Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('openCreate')
        ->set('name', 'Тестовый Мастер')
        ->set('specialization_id', $specialization->id)
        ->set('schedule', $schedule)
        ->call('save')
        ->assertHasNoErrors();

    $barber = Barber::where('name', 'Тестовый Мастер')->firstOrFail();
    expect($barber->scheduleWindowForDay('sun'))->toBe('off');
});

it('clears a day\'s stale times the moment it is toggled off', function () {
    $component = Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('openCreate')
        ->set('schedule.mon.off', true);

    $component->assertSet('schedule.mon.start', null)
        ->assertSet('schedule.mon.end', null);
});

it('hydrates a legacy positional schedule into the new field shape when editing', function () {
    $barber = Barber::factory()->create([
        'schedule' => [
            'mon' => ['10:00', '19:00'],
            'tue' => null,
            'wed' => ['10:00', '19:00'],
            'thu' => ['10:00', '19:00'],
            'fri' => ['10:00', '19:00'],
            'sat' => ['10:00', '16:00'],
            'sun' => null,
        ],
    ]);

    Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('edit', $barber->id)
        ->assertSet('schedule.mon.start', '10:00')
        ->assertSet('schedule.mon.end', '19:00')
        ->assertSet('schedule.mon.off', false)
        // Тот же день, которого раньше вовсе не было в записи, получает те же
        // значения по умолчанию, что и новый мастер — не пустые поля, которые
        // блокировали бы сохранение формы из-за несвязанной правки.
        ->assertSet('schedule.tue.off', false)
        ->assertSet('schedule.tue.start', '09:00');
});

// --- Public booking page: reading the schedule ------------------------------

it('narrows the public booking slots to the barber\'s own schedule for that weekday', function () {
    $this->travelTo(now()->next(Carbon::MONDAY)->setTime(7, 0));

    $barber = Barber::factory()->create([
        'schedule' => [
            'mon' => ['start' => '11:00', 'end' => '15:00', 'off' => false],
        ],
    ]);

    $slots = Livewire::test('pages.booking')
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->instance()
        ->availableSlots;

    expect(array_column($slots, 'value'))->toBe(['11:00', '12:00', '13:00', '14:00']);
});

it('offers nothing on a day the barber marked off', function () {
    $this->travelTo(now()->next(Carbon::TUESDAY)->setTime(7, 0));

    $barber = Barber::factory()->create([
        'schedule' => [
            'tue' => ['start' => null, 'end' => null, 'off' => true],
        ],
    ]);

    $slots = Livewire::test('pages.booking')
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->instance()
        ->availableSlots;

    expect($slots)->toBe([]);
});

it('falls back to the salon hours for a weekday the barber has no schedule entry for', function () {
    $this->travelTo(now()->next(Carbon::WEDNESDAY)->setTime(7, 0));

    // Только понедельник настроен — среды в массиве попросту нет, обычная
    // форма записи мастера, который никогда не трогал этот день.
    $barber = Barber::factory()->create([
        'schedule' => [
            'mon' => ['start' => '11:00', 'end' => '15:00', 'off' => false],
        ],
    ]);

    $slots = Livewire::test('pages.booking')
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->instance()
        ->availableSlots;

    // Часы салона по умолчанию: 09:00-21:00.
    expect(array_column($slots, 'value'))->toBe([
        '09:00', '10:00', '11:00', '12:00', '13:00', '14:00',
        '15:00', '16:00', '17:00', '18:00', '19:00', '20:00',
    ]);
});

it('does not let a legacy start/end pair silently narrow anyone the day it ships', function () {
    // Позиционную пару сохраняла форма, расписание которой никто не читал —
    // такие значения есть почти у каждого мастера. Начать соблюдать их на
    // деплое значило бы молча сузить часы всему салону разом, поэтому они
    // считаются отсутствием расписания до явного пересохранения.
    $this->travelTo(now()->next(Carbon::THURSDAY)->setTime(7, 0));

    $barber = Barber::factory()->create([
        'schedule' => [
            'thu' => ['10:00', '16:00'],
        ],
    ]);

    $slots = Livewire::test('pages.booking')
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->instance()
        ->availableSlots;

    expect(array_column($slots, 'value'))->toBe([
        '09:00', '10:00', '11:00', '12:00', '13:00', '14:00',
        '15:00', '16:00', '17:00', '18:00', '19:00', '20:00',
    ]);
});

it('shows the legacy pair in the admin form so it can be confirmed on purpose', function () {
    $barber = Barber::factory()->create([
        'schedule' => [
            'thu' => ['10:00', '16:00'],
        ],
    ]);

    Livewire::actingAs(scheduleAdmin())
        ->test('pages.admin.barbers')
        ->call('edit', $barber->id)
        ->assertSet('schedule.thu.start', '10:00')
        ->assertSet('schedule.thu.end', '16:00')
        ->assertSet('schedule.thu.off', false);
});

it('behaves exactly like a barber with no schedule at all when the column is null', function () {
    $this->travelTo(now()->next(Carbon::FRIDAY)->setTime(7, 0));

    $barber = Barber::factory()->create(['schedule' => null]);

    $slots = Livewire::test('pages.booking')
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->instance()
        ->availableSlots;

    expect(array_column($slots, 'value'))->toBe([
        '09:00', '10:00', '11:00', '12:00', '13:00', '14:00',
        '15:00', '16:00', '17:00', '18:00', '19:00', '20:00',
    ]);
});

// --- Public booking page: crafted urls and malformed data -------------------

it('refuses a crafted url time that falls outside the barber\'s narrowed schedule for the day', function () {
    $this->travelTo(now()->next(Carbon::MONDAY)->setTime(7, 0));

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create([
        'schedule' => [
            'mon' => ['start' => '11:00', 'end' => '15:00', 'off' => false],
        ],
    ]);
    $date = now()->toDateString();

    // 09:00 sits inside the salon's own hours but before this barber's own
    // Monday window opens — a link crafted around the salon-wide hours must
    // still be rejected once the barber narrows them further.
    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
        'time' => '09:00',
    ])->test('pages.booking')
        ->assertSet('time', null)
        ->assertSet('step', 3);
});

it('cannot confirm an appointment outside the barber\'s narrowed schedule by setting time directly', function () {
    $this->travelTo(now()->next(Carbon::MONDAY)->setTime(7, 0));

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create([
        'schedule' => [
            'mon' => ['start' => '11:00', 'end' => '15:00', 'off' => false],
        ],
    ]);
    $date = now()->toDateString();

    // The component is hydrated from a deep link with no time (mount()'s
    // sanitizing never sees one), then the attacker writes straight to the
    // public $time property — bypassing selectTime()'s guard entirely.
    // confirm() must still refuse it server-side.
    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
    ])->test('pages.booking')
        ->set('time', '09:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasErrors('time')
        ->assertSet('step', 3);

    expect(Appointment::where('barber_id', $barber->id)->count())->toBe(0)
        ->and(Client::where('phone', '998901112233')->exists())->toBeFalse();
});

it('refuses a crafted url time on a day the barber has marked off', function () {
    $this->travelTo(now()->next(Carbon::TUESDAY)->setTime(7, 0));

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create([
        'schedule' => [
            'tue' => ['start' => null, 'end' => null, 'off' => true],
        ],
    ]);
    $date = now()->toDateString();

    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
        'time' => '10:00',
    ])->test('pages.booking')
        ->assertSet('time', null)
        ->assertSet('step', 3);
});

it('cannot confirm an appointment on a day the barber has marked off by setting time directly', function () {
    $this->travelTo(now()->next(Carbon::TUESDAY)->setTime(7, 0));

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create([
        'schedule' => [
            'tue' => ['start' => null, 'end' => null, 'off' => true],
        ],
    ]);
    $date = now()->toDateString();

    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
    ])->test('pages.booking')
        ->set('time', '10:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112244')
        ->call('confirm')
        ->assertHasErrors('time')
        ->assertSet('step', 3);

    expect(Appointment::where('barber_id', $barber->id)->count())->toBe(0)
        ->and(Client::where('phone', '998901112244')->exists())->toBeFalse();
});

it('does not 500 the public page when the schedule column holds malformed values', function () {
    $this->travelTo(now()->next(Carbon::WEDNESDAY)->setTime(7, 0));

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();

    // Writes straight to the column, bypassing the model's own cast and
    // scheduleWindowForDay()'s validation, to simulate whatever shape could
    // still reach the database through a bug, a manual edit, or data that
    // predates even the legacy positional format.
    DB::table('barbers')->where('id', $barber->id)->update([
        'schedule' => json_encode([
            'mon' => 'not-an-array',
            'tue' => ['start' => 123, 'end' => null],
            'wed' => ['start' => '99:99', 'end' => '10:00'],
            'thu' => ['10:00'],
            'fri' => [],
        ]),
    ]);

    $date = now()->toDateString();

    $slots = Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
    ])->test('pages.booking')
        ->instance()
        ->availableSlots;

    // Wednesday's entry is unparseable (not a valid H:i pair), so it falls
    // back to the salon's own hours exactly like a barber with no schedule.
    expect(array_column($slots, 'value'))->toBe([
        '09:00', '10:00', '11:00', '12:00', '13:00', '14:00',
        '15:00', '16:00', '17:00', '18:00', '19:00', '20:00',
    ]);

    // The same request through the real HTTP stack — not just the Livewire
    // component in isolation — must render, not blow up with a 500.
    $this->get(route('booking', [
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
    ]))->assertOk();
});
