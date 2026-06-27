<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Telegram\AppointmentFormatter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function serviceAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('returns the name for the active locale with a Russian fallback', function () {
    $service = Service::create([
        'name' => Service::encodeTranslations([
            'ru' => 'Окрашивание бороды',
            'uz' => 'Soqol boʻyash',
            'kaa' => 'Saqal boyaw',
        ]),
        'duration_minutes' => 30,
    ]);

    $expected = [
        'ru' => 'Окрашивание бороды',
        'uz' => 'Soqol boʻyash',
        'kaa' => 'Saqal boyaw',
        'en' => 'Окрашивание бороды', // unsupported locale falls back to ru
    ];

    foreach ($expected as $locale => $name) {
        app()->setLocale($locale);
        expect(Service::find($service->id)->name)->toBe($name);
    }
});

it('falls back to Russian when the locale translation is empty', function () {
    $service = Service::create([
        'name' => Service::encodeTranslations(['ru' => 'Укладка', 'uz' => '', 'kaa' => '']),
        'duration_minutes' => 30,
    ]);

    app()->setLocale('uz');

    expect(Service::find($service->id)->name)->toBe('Укладка');
});

it('treats a legacy plain-string name as the value for every locale', function () {
    $service = Service::create([
        'name' => Service::encodeTranslations(['ru' => 'tmp', 'uz' => 'tmp', 'kaa' => 'tmp']),
        'duration_minutes' => 30,
    ]);

    // Simulate a pre-migration row holding a single plain name.
    DB::table('services')->where('id', $service->id)->update(['name' => 'Мужская стрижка']);

    app()->setLocale('kaa');

    expect(Service::find($service->id)->name)->toBe('Мужская стрижка')
        ->and(Service::find($service->id)->translations)->toBe([
            'ru' => 'Мужская стрижка',
            'uz' => 'Мужская стрижка',
            'kaa' => 'Мужская стрижка',
        ]);
});

it('lets an admin create a service with three names', function () {
    Livewire::actingAs(serviceAdmin())
        ->test('pages.admin.services')
        ->call('openCreate')
        ->set('name_ru', 'Окрашивание волос')
        ->set('name_uz', 'Soch boʻyash')
        ->set('name_kaa', 'Shash boyaw')
        ->set('duration_minutes', 60)
        ->call('save')
        ->assertHasNoErrors();

    $service = Service::latest('id')->first();

    expect($service->translations)->toBe([
        'ru' => 'Окрашивание волос',
        'uz' => 'Soch boʻyash',
        'kaa' => 'Shash boyaw',
    ])->and($service->duration_minutes)->toBe(60);
});

it('pre-fills all three name fields when editing a service', function () {
    $service = Service::create([
        'name' => Service::encodeTranslations([
            'ru' => 'Чистка лица',
            'uz' => 'Yuz tozalash',
            'kaa' => 'Bet tazalaw',
        ]),
        'duration_minutes' => 45,
    ]);

    Livewire::actingAs(serviceAdmin())
        ->test('pages.admin.services')
        ->call('edit', $service->id)
        ->assertSet('name_ru', 'Чистка лица')
        ->assertSet('name_uz', 'Yuz tozalash')
        ->assertSet('name_kaa', 'Bet tazalaw');
});

it('requires every name translation', function () {
    Livewire::actingAs(serviceAdmin())
        ->test('pages.admin.services')
        ->call('openCreate')
        ->set('name_ru', 'Только русский')
        ->set('name_uz', '')
        ->set('name_kaa', '')
        ->set('duration_minutes', 30)
        ->call('save')
        ->assertHasErrors(['name_uz', 'name_kaa']);
});

it('localizes service names in appointment notification summaries', function () {
    app()->setLocale('uz');

    $service = Service::factory()->create([
        'name' => Service::encodeTranslations([
            'ru' => 'Укладка',
            'uz' => 'Soch turmagi',
            'kaa' => 'Ukladka',
        ]),
    ]);

    $appointment = Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => Barber::factory()->create()->id,
        'starts_at' => now(),
        'ends_at' => now()->addHour(),
        'status' => AppointmentStatus::Pending,
    ]);
    $appointment->services()->sync([$service->id => ['amount' => 0]]);

    expect(AppointmentFormatter::services($appointment->fresh()))->toBe('Soch turmagi');
});

it('seeds the base catalogue and upgrades a legacy single-name service', function () {
    // A pre-existing service matching a base entry by its Russian name should be
    // upgraded with translations in place, not duplicated.
    $legacy = Service::create([
        'name' => Service::encodeTranslations([
            'ru' => 'Мужская стрижка',
            'uz' => 'Мужская стрижка',
            'kaa' => 'Мужская стрижка',
        ]),
        'duration_minutes' => 45,
    ]);

    $this->seed(DatabaseSeeder::class);

    expect(Service::count())->toBe(7); // 1 upgraded + 6 created, no duplicate

    app()->setLocale('uz');
    expect(Service::find($legacy->id)->name)->toBe('Soch olish (Erkaklar)');
});

it('backfills production single-language names with full translations', function () {
    // Mirror real production rows: each name stored in a single language.
    DB::table('services')->insert([
        ['name' => 'Bet tazalaw', 'duration_minutes' => 60, 'is_active' => true],   // KAA
        ['name' => 'Мужская стрижка', 'duration_minutes' => 60, 'is_active' => true], // RU
        ['name' => 'Своя услуга', 'duration_minutes' => 20, 'is_active' => true],     // unknown
    ]);

    // Re-run the data backfill over the freshly inserted legacy rows (idempotent).
    (require database_path('migrations/2026_06_27_155516_make_services_name_translatable.php'))->up();

    $byKaa = Service::all()->keyBy(fn (Service $service) => $service->translations['kaa']);

    expect($byKaa['Bet tazalaw']->translations)->toBe([
        'ru' => 'Чистка лица', 'uz' => 'Yuz tozalash', 'kaa' => 'Bet tazalaw',
    ])->and($byKaa['Shash alıw (Er adamlar)']->translations)->toBe([
        'ru' => 'Мужская стрижка', 'uz' => 'Soch olish (Erkaklar)', 'kaa' => 'Shash alıw (Er adamlar)',
    ])->and($byKaa['Своя услуга']->translations)->toBe([
        'ru' => 'Своя услуга', 'uz' => 'Своя услуга', 'kaa' => 'Своя услуга',
    ]);

    // Durations are preserved by the name-only backfill.
    expect(Service::where('duration_minutes', 60)->count())->toBe(2);
});
