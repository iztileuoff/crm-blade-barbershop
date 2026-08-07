<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Slots are now time-aware: freeze the clock mid-morning so today still has
    // free hours ahead of it whenever the suite happens to run.
    $this->travelTo(now()->startOfDay()->addHours(10));
});

it('lets a guest open the booking page without logging in', function () {
    $this->get(route('booking'))
        ->assertOk()
        ->assertSee('Выберите услугу');
});

it('lets a guest create an appointment', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->call('selectTime', '12:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    expect(Appointment::count())->toBe(1)
        ->and(Client::where('phone', '998901112233')->exists())->toBeTrue();
});

it('quotes the price of the booked service, not the barber base price', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create(['price' => 50000]);
    $barber->services()->attach($service->id, ['price' => 120000]);

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->call('selectTime', '12:00')
        ->assertSet('step', 4)
        ->assertSee('120 000')
        ->assertDontSee('50 000')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertSet('step', 5)
        ->assertSee('120 000');

    expect(Appointment::first()->price)->toBe(120000);
});

it('offers only the hours inside the configured working day', function () {
    Setting::set('work_start', '10:00');
    Setting::set('work_end', '14:00');

    $slots = Volt::test('pages.booking')
        ->set('date', now()->addDay()->toDateString())
        ->instance()
        ->availableSlots;

    expect(array_column($slots, 'value'))->toBe(['10:00', '11:00', '12:00', '13:00']);
});

it('runs the grid to 23:00 when the shop closes at midnight', function () {
    // `<input type="time">` не умеет 24:00, поэтому «до полуночи» админ может
    // сохранить только как 00:00. Прочитанное буквально это 0-й час — диапазон
    // переворачивался, и салон молча откатывался на дефолтные 09–20 (issue #96).
    Setting::set('work_start', '09:00');
    Setting::set('work_end', '00:00');

    $slots = Volt::test('pages.booking')
        ->set('date', now()->addDay()->toDateString())
        ->instance()
        ->availableSlots;

    expect(array_column($slots, 'value'))->toBe([
        '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00',
        '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00', '23:00',
    ]);
});

it('keeps midnight meaning hour zero at the opening end', function () {
    // Полночь как конец дня — это 24-й час, но как начало она обязана остаться
    // нулевым: круглосуточный салон отдаёт все 24 слота, а не пустую сетку.
    Setting::set('work_start', '00:00');
    Setting::set('work_end', '00:00');

    $slots = Volt::test('pages.booking')
        ->set('date', now()->addDay()->toDateString())
        ->instance()
        ->availableSlots;

    expect($slots)->toHaveCount(24)
        ->and($slots[0]['value'])->toBe('00:00')
        ->and($slots[23]['value'])->toBe('23:00');
});

it('lets a barber work to midnight without collapsing their day', function () {
    // hourBoundary() округляет конец вниз, так что расписание «до 00:00»
    // давало 0 и вырезало мастеру весь день.
    Setting::set('work_start', '09:00');
    Setting::set('work_end', '00:00');

    $barber = Barber::factory()->create([
        'schedule' => [
            'mon' => ['start' => '20:00', 'end' => '00:00', 'off' => false],
            'tue' => ['start' => '20:00', 'end' => '00:00', 'off' => false],
            'wed' => ['start' => '20:00', 'end' => '00:00', 'off' => false],
            'thu' => ['start' => '20:00', 'end' => '00:00', 'off' => false],
            'fri' => ['start' => '20:00', 'end' => '00:00', 'off' => false],
            'sat' => ['start' => '20:00', 'end' => '00:00', 'off' => false],
            'sun' => ['start' => '20:00', 'end' => '00:00', 'off' => false],
        ],
    ]);

    $slots = Volt::test('pages.booking')
        ->set('barberId', $barber->id)
        ->set('date', now()->addDay()->toDateString())
        ->instance()
        ->availableSlots;

    expect(array_column($slots, 'value'))->toBe(['20:00', '21:00', '22:00', '23:00']);
});

it('falls back to the default hours when the settings are broken', function () {
    Setting::set('work_start', '20:00');
    Setting::set('work_end', '08:00');

    $slots = Volt::test('pages.booking')
        ->set('date', now()->addDay()->toDateString())
        ->instance()
        ->availableSlots;

    expect(array_column($slots, 'value'))->toBe([
        '09:00', '10:00', '11:00', '12:00', '13:00', '14:00',
        '15:00', '16:00', '17:00', '18:00', '19:00', '20:00',
    ]);
});

it('drops hours that have already started today', function () {
    $this->travelTo(now()->startOfDay()->addHours(12)->addMinutes(30));

    $slots = Volt::test('pages.booking')->instance()->availableSlots;
    $values = array_column($slots, 'value');

    expect($values)->not->toContain('12:00')
        ->and($values[0])->toBe('13:00');
});

it('falls back to today when the client sends a malformed date', function () {
    $slots = Volt::test('pages.booking')
        ->set('date', 'not-a-date')
        ->instance()
        ->availableSlots;

    // The clock is frozen at 10:00, so today's first bookable hour is 11:00.
    expect(array_column($slots, 'value')[0])->toBe('11:00');
});

it('refuses to select a slot that is already taken', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    Appointment::factory()->create([
        'barber_id' => $barber->id,
        'starts_at' => $date.' 12:00:00',
        'ends_at' => $date.' 13:00:00',
        'status' => AppointmentStatus::Pending,
    ]);

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->call('selectTime', '12:00')
        ->assertSet('step', 3)
        ->assertSet('time', null);
});

it('rejects a booking whose slot was taken while the guest filled the form', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    $component = Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->call('selectTime', '12:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233');

    Appointment::factory()->create([
        'barber_id' => $barber->id,
        'starts_at' => $date.' 12:00:00',
        'ends_at' => $date.' 13:00:00',
        'status' => AppointmentStatus::Pending,
    ]);

    $component->call('confirm')
        ->assertHasErrors('time')
        ->assertSet('step', 3);

    expect(Appointment::count())->toBe(1)
        ->and(Client::where('phone', '998901112233')->exists())->toBeFalse();
});

it('does not reveal a stored name or birth date when the phone is typed', function () {
    Client::factory()->create([
        'name' => 'Старый Клиент',
        'phone' => '998901112233',
        'birth_date' => '1990-05-15',
    ]);

    Volt::test('pages.booking')
        ->set('phone', '998 90 111 22 33')
        ->assertSet('name', '')
        ->assertSet('birth_date', '')
        ->assertSet('clientFound', true)
        ->assertDontSee('Старый Клиент')
        ->assertDontSee('1990-05-15');
});

it('fills the card for a signed-in admin instead of making them retype it', function (Role $role) {
    // Админ и так открывает эту карточку в CRM — заставлять его перенабирать
    // имя из базы значит напрашиваться на опечатку в чужой записи (issue #97).
    Client::factory()->create([
        'name' => 'Старый Клиент',
        'phone' => '998901112233',
        'birth_date' => '1990-05-15',
    ]);

    Livewire::actingAs(User::factory()->create(['role' => $role]))
        ->test('pages.booking')
        ->set('phone', '998 90 111 22 33')
        ->assertSet('clientFound', true)
        ->assertSet('name', 'Старый Клиент')
        ->assertSet('birth_date', '1990-05-15');
})->with([
    'admin' => Role::ADMIN,
    'super admin' => Role::SUPER_ADMIN,
]);

it('does not turn the booking form into a client lookup for a barber', function () {
    // RestrictBarberAccess не пускает мастера на admin.clients: имя клиента он
    // видит только в своих записях. Подстановка вернула бы ему ровно тот поиск
    // «телефон → карточка», который этот middleware и запрещает.
    Client::factory()->create([
        'name' => 'Чужой Клиент',
        'phone' => '998901112233',
        'birth_date' => '1990-05-15',
    ]);

    Livewire::actingAs(User::factory()->create(['role' => Role::BARBER]))
        ->test('pages.booking')
        ->set('phone', '998901112233')
        ->assertSet('clientFound', true)
        ->assertSet('name', '')
        ->assertSet('birth_date', '')
        ->assertDontSee('Чужой Клиент')
        ->assertDontSee('1990-05-15');
});

it('clears a prefilled card once staff move to a number with no card', function () {
    // Иначе исправление одной цифры в номере тихо унесло бы имя предыдущего
    // клиента на карточку нового.
    Client::factory()->create(['name' => 'Старый Клиент', 'phone' => '998901112233', 'birth_date' => '1990-05-15']);

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.booking')
        ->set('phone', '998901112233')
        ->assertSet('name', 'Старый Клиент')
        ->set('phone', '998909998877')
        ->assertSet('clientFound', false)
        ->assertSet('name', '')
        ->assertSet('birth_date', '');
});

it('does not let an empty field on the card erase what staff already typed', function () {
    // Карточка без даты рождения должна принять вводимую, а не стереть её.
    Client::factory()->create(['name' => 'Старый Клиент', 'phone' => '998901112233', 'birth_date' => null]);

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.booking')
        ->set('birth_date', '2000-01-01')
        ->set('phone', '998901112233')
        ->assertSet('name', 'Старый Клиент')
        ->assertSet('birth_date', '2000-01-01');
});

it('never prefills from a card a guest could not otherwise see', function () {
    // Тот же путь, что и у персонала, но без сессии: страница публичная, и
    // подстановка превратила бы её в справочник «телефон → имя + дата рождения»
    // для любого, кто наберёт чужой номер.
    Client::factory()->create([
        'name' => 'Старый Клиент',
        'phone' => '998901112233',
        'birth_date' => '1990-05-15',
    ]);

    $guest = Volt::test('pages.booking')
        ->set('phone', '998901112233')
        ->assertSet('clientFound', true)
        ->assertSet('name', '')
        ->assertSet('birth_date', '');

    // Флаг под #[Locked] — гость не может выписать себе ветку с карточкой.
    expect(fn () => $guest->set('prefilledFromCard', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('drops the found flag when the phone changes to an unknown number', function () {
    Client::factory()->create(['phone' => '998901112233']);

    Volt::test('pages.booking')
        ->set('phone', '998901112233')
        ->assertSet('clientFound', true)
        ->set('phone', '998909998877')
        ->assertSet('clientFound', false);
});

it('keeps values the user typed by hand for an unknown phone', function () {
    Volt::test('pages.booking')
        ->set('phone', '998909998877')
        ->set('name', 'Мой Ввод')
        ->set('birth_date', '2000-01-01')
        ->set('phone', '998909990000')
        ->assertSet('name', 'Мой Ввод')
        ->assertSet('birth_date', '2000-01-01')
        ->assertSet('clientFound', false);
});

it('leaves fields empty for an unknown phone', function () {
    Volt::test('pages.booking')
        ->set('phone', '998909998877')
        ->assertSet('name', '')
        ->assertSet('birth_date', '')
        ->assertSet('clientFound', false);
});

it('never overwrites an existing client card from the public form', function () {
    $client = Client::factory()->create([
        'name' => 'Старый Клиент',
        'phone' => '998901112233',
        'birth_date' => '1990-05-15',
    ]);

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->call('selectTime', '12:00')
        ->set('name', 'Чужое Имя')
        ->set('phone', '998901112233')
        ->set('birth_date', '2000-01-01')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    $client->refresh();

    expect(Client::count())->toBe(1)
        ->and($client->name)->toBe('Старый Клиент')
        ->and($client->birth_date->toDateString())->toBe('1990-05-15')
        ->and($client->appointments()->count())->toBe(1);
});

it('shows the service name in the selected locale on the booking page', function () {
    Service::factory()->create([
        'name' => Service::encodeTranslations([
            'ru' => 'Окрашивание бороды',
            'uz' => 'Soqol boʻyash',
            'kaa' => 'Saqal boyaw',
        ]),
    ]);

    $this->withSession(['locale' => 'uz'])
        ->get(route('booking'))
        ->assertOk()
        ->assertSee('Soqol boʻyash')
        ->assertDontSee('Окрашивание бороды');

    $this->withSession(['locale' => 'kaa'])
        ->get(route('booking'))
        ->assertOk()
        ->assertSee('Saqal boyaw');
});

it('throttles a flood of bookings from the same client', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    // Each attempt takes a different hour: the slot guard would reject repeats
    // of the same time long before the rate limiter got a chance to speak.
    $book = fn (int $hour) => Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->call('selectTime', sprintf('%02d:00', $hour))
        ->set('name', 'Спам Бот')
        ->set('phone', '998901112233')
        ->call('confirm');

    for ($i = 0; $i < 5; $i++) {
        $book(12 + $i)->assertHasNoErrors()->assertSet('step', 5);
    }

    $book(17)->assertHasErrors('phone');

    expect(Appointment::count())->toBe(5);
});

it('refuses to create a second appointment from a rapid double submit', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    $component = Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->call('selectTime', '12:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233');

    $component->call('confirm')->assertHasNoErrors()->assertSet('step', 5);

    // The second tap of a double-tap reaches the server after the first one
    // already booked the slot — it must be rejected, not double-booked.
    $component->call('confirm')
        ->assertHasErrors('time')
        ->assertSet('step', 3);

    expect(Appointment::count())->toBe(1);
});

it('drops a service id from the url that does not exist', function () {
    Livewire::withQueryParams(['serviceId' => 999999])
        ->test('pages.booking')
        ->assertSet('serviceId', null)
        ->assertSet('step', 1);
});

it('drops a service id from the url that belongs to an inactive service', function () {
    $service = Service::factory()->create(['is_active' => false]);

    Livewire::withQueryParams(['serviceId' => $service->id])
        ->test('pages.booking')
        ->assertSet('serviceId', null)
        ->assertSet('step', 1);
});

it('drops a barber id from the url that belongs to an inactive barber', function () {
    $service = Service::factory()->create();
    $barber = Barber::factory()->create(['is_active' => false]);

    Livewire::withQueryParams(['serviceId' => $service->id, 'barberId' => $barber->id])
        ->test('pages.booking')
        ->assertSet('barberId', null)
        ->assertSet('step', 2);
});

it('drops a barber id from the url when no service was selected', function () {
    $barber = Barber::factory()->create();

    Livewire::withQueryParams(['barberId' => $barber->id])
        ->test('pages.booking')
        ->assertSet('barberId', null)
        ->assertSet('step', 1);
});

it('drops a date from the url that has already passed', function () {
    Livewire::withQueryParams(['date' => now()->subDay()->toDateString()])
        ->test('pages.booking')
        ->assertSet('date', now()->toDateString());
});

it('drops a date from the url that is past the visible horizon', function () {
    Livewire::withQueryParams(['date' => now()->addDays(30)->toDateString()])
        ->test('pages.booking')
        ->assertSet('date', now()->toDateString());
});

it('drops a malformed date from the url instead of crashing', function () {
    Livewire::withQueryParams(['date' => '2026-02-30'])
        ->test('pages.booking')
        ->assertSet('date', now()->toDateString());
});

it('drops a time from the url that is already taken for that barber', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    Appointment::factory()->create([
        'barber_id' => $barber->id,
        'starts_at' => $date.' 12:00:00',
        'ends_at' => $date.' 13:00:00',
        'status' => AppointmentStatus::Pending,
    ]);

    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
        'time' => '12:00',
    ])->test('pages.booking')
        ->assertSet('time', null)
        ->assertSet('step', 3);
});

it('restores a full valid selection from the url and lands on the matching step', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
        'time' => '12:00',
    ])->test('pages.booking')
        ->assertSet('serviceId', $service->id)
        ->assertSet('barberId', $barber->id)
        ->assertSet('date', $date)
        ->assertSet('time', '12:00')
        ->assertSet('step', 4);
});

it('never lets a url jump straight to the success step', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->toDateString();

    Livewire::withQueryParams([
        'step' => 5,
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
        'time' => '12:00',
    ])->test('pages.booking')
        ->assertSet('step', 4);
});

it('ignores a step from the url that outruns the validated selection', function () {
    Livewire::withQueryParams(['step' => 3])
        ->test('pages.booking')
        ->assertSet('step', 1);
});

it('never populates the name or phone from the url', function () {
    Livewire::withQueryParams(['name' => 'Hacker', 'phone' => '998901112233'])
        ->test('pages.booking')
        ->assertSet('name', '')
        ->assertSet('phone', '');
});

it('drops a barber id from the url when the service id itself was invalid', function () {
    $barber = Barber::factory()->create();

    Livewire::withQueryParams(['serviceId' => 999999, 'barberId' => $barber->id])
        ->test('pages.booking')
        ->assertSet('serviceId', null)
        ->assertSet('barberId', null)
        ->assertSet('step', 1);
});

it('drops an out-of-hours time from the url', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->addDay()->toDateString();

    // Default working hours are 09:00-21:00; 22:00 is well-formed but outside them.
    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
        'time' => '22:00',
    ])->test('pages.booking')
        ->assertSet('time', null)
        ->assertSet('step', 3);
});

it('lets a barber without a custom price for the service still reach the time step from a deep link', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    // No services()->attach() here: this barber has never been given a custom
    // price for $service. The app has no notion of "a barber who cannot do a
    // service" — every active barber is bookable for every active service and
    // falls back to their base price — so this pairing is not tampering and
    // must reach the same step the click path would land it on.
    $barber = Barber::factory()->create(['price' => 30000]);
    $date = now()->addDay()->toDateString();

    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
    ])->test('pages.booking')
        ->assertSet('serviceId', $service->id)
        ->assertSet('barberId', $barber->id)
        ->assertSet('step', 3);
});

it('clamps a step from the url that outruns a partial valid selection', function () {
    $service = Service::factory()->create();

    // Only the service is valid; the url still asks to start on step 4.
    Livewire::withQueryParams(['step' => 4, 'serviceId' => $service->id])
        ->test('pages.booking')
        ->assertSet('step', 2);
});

it('recovers a bookable state when only the date in an otherwise valid deep link is malformed', function () {
    $this->travelTo(now()->startOfDay()->addHours(8));

    $service = Service::factory()->create(['duration_minutes' => 60]);
    // No schedule: falls back to the salon-wide hours (09:00-21:00) regardless
    // of which weekday "today" happens to be when this test runs — the factory's
    // default schedule starts most weekdays at 10:00, which would make 09:00
    // unbookable and this test flaky by day of week (#73).
    $barber = Barber::factory()->create(['schedule' => null]);

    // The date is sanitized to today before the time is checked against it, so
    // a still-valid time for today survives and the selection stays bookable.
    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => '2026-02-30',
        'time' => '09:00',
    ])->test('pages.booking')
        ->assertSet('date', now()->toDateString())
        ->assertSet('time', '09:00')
        ->assertSet('step', 4);
});

it('completes a booking that started from a fully valid deep link', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->addDay()->toDateString();

    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
        'time' => '12:00',
    ])->test('pages.booking')
        ->assertSet('step', 4)
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    expect(Appointment::count())->toBe(1);
});

it("writes the app's active locale onto a brand-new client at booking time", function () {
    app()->setLocale('kaa');

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->call('selectTime', '12:00')
        ->set('name', 'Jańa Klient')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    expect(Client::where('phone', '998901112233')->first()->locale)->toBe('kaa');
});

it('overwrites an existing client locale with the language used for this booking', function () {
    $client = Client::factory()->create(['phone' => '998901112233', 'locale' => 'ru']);

    app()->setLocale('uz');

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->call('selectTime', '12:00')
        ->set('name', 'Ismi')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    expect($client->fresh()->locale)->toBe('uz');
});

it('shows the salon contacts and the booking number on the success screen', function () {
    Setting::set('shop_phone', '+998 90 123 45 67');
    Setting::set('shop_address', 'Ташкент, ул. Амира Темура, 1');
    Setting::set('telegram', '@blade_barbershop');

    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();

    $component = Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->call('selectTime', '12:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5);

    $appointmentId = Appointment::first()->id;

    $component
        ->assertSee('#'.$appointmentId)
        ->assertSee('+998 90 123 45 67')
        ->assertSee('Ташкент, ул. Амира Темура, 1')
        ->assertSeeHtml('tel:998901234567')
        ->assertSeeHtml('https://t.me/blade_barbershop');
});

it('hides the contacts card entirely when no salon contacts are configured', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();

    Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', now()->toDateString())
        ->call('selectTime', '12:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 5)
        ->assertDontSee(__('booking.success.contacts_title'));
});

it('cannot confirm an appointment for a taken slot by setting time directly after loading a deep link', function () {
    $service = Service::factory()->create(['duration_minutes' => 60]);
    $barber = Barber::factory()->create();
    $date = now()->addDay()->toDateString();

    Appointment::factory()->create([
        'barber_id' => $barber->id,
        'starts_at' => $date.' 12:00:00',
        'ends_at' => $date.' 13:00:00',
        'status' => AppointmentStatus::Pending,
    ]);

    // The component is hydrated from a deep link (not from ->set() calls), then
    // the attacker writes straight to the public $time property — bypassing
    // both selectTime()'s guard and mount()'s url sanitizing. confirm() must
    // still refuse it server-side.
    Livewire::withQueryParams([
        'serviceId' => $service->id,
        'barberId' => $barber->id,
        'date' => $date,
    ])->test('pages.booking')
        ->set('time', '12:00')
        ->set('name', 'Гость Клиент')
        ->set('phone', '998901112233')
        ->call('confirm')
        ->assertHasErrors('time')
        ->assertSet('step', 3);

    expect(Appointment::where('barber_id', $barber->id)->count())->toBe(1)
        ->and(Client::where('phone', '998901112233')->exists())->toBeFalse();
});
