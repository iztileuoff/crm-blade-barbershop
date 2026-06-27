<?php

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

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

    $book = fn () => Volt::test('pages.booking')
        ->call('selectService', $service->id)
        ->call('selectBarber', $barber->id)
        ->set('date', $date)
        ->call('selectTime', '12:00')
        ->set('name', 'Спам Бот')
        ->set('phone', '998901112233')
        ->call('confirm');

    for ($i = 0; $i < 5; $i++) {
        $book()->assertHasNoErrors()->assertSet('step', 5);
    }

    $book()->assertHasErrors('phone');

    expect(Appointment::count())->toBe(5);
});
