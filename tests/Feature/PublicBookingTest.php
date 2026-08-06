<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
