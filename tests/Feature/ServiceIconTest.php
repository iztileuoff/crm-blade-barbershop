<?php

use App\Enums\Role;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function iconAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('orders services by id ascending regardless of name', function () {
    // Insert out of alphabetical order so a name sort would differ from id sort.
    $second = Service::factory()->create(['name' => Service::encodeTranslations(['ru' => 'Яяя', 'uz' => 'Яяя', 'kaa' => 'Яяя'])]);
    $first = Service::factory()->create(['name' => Service::encodeTranslations(['ru' => 'Ааа', 'uz' => 'Ааа', 'kaa' => 'Ааа'])]);

    expect(Service::ordered()->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('lets an admin create a service with a chosen icon', function () {
    Livewire::actingAs(iconAdmin())
        ->test('pages.admin.services')
        ->call('openCreate')
        ->set('name_ru', 'Окрашивание волос')
        ->set('name_uz', 'Soch boʻyash')
        ->set('name_kaa', 'Shash boyaw')
        ->set('icon', 'sparkles')
        ->set('duration_minutes', 60)
        ->call('save')
        ->assertHasNoErrors();

    expect(Service::latest('id')->first()->icon)->toBe('sparkles');
});

it('rejects an unknown icon key', function () {
    Livewire::actingAs(iconAdmin())
        ->test('pages.admin.services')
        ->call('openCreate')
        ->set('name_ru', 'Тест')
        ->set('name_uz', 'Test')
        ->set('name_kaa', 'Test')
        ->set('icon', 'not-a-real-icon')
        ->set('duration_minutes', 30)
        ->call('save')
        ->assertHasErrors(['icon']);
});

it('pre-fills the icon when editing and defaults unknown icons', function () {
    $service = Service::factory()->create(['icon' => 'beaker']);

    Livewire::actingAs(iconAdmin())
        ->test('pages.admin.services')
        ->call('edit', $service->id)
        ->assertSet('icon', 'beaker');

    // A row whose stored icon is no longer valid falls back to the default.
    DB::table('services')->where('id', $service->id)->update(['icon' => 'gone']);

    Livewire::actingAs(iconAdmin())
        ->test('pages.admin.services')
        ->call('edit', $service->id)
        ->assertSet('icon', Service::DEFAULT_ICON);
});

it('renders the default icon for a service without one', function () {
    $service = Service::factory()->create(['icon' => null]);

    $html = Blade::render('<x-service-icon :name="$icon" />', ['icon' => $service->icon]);

    // The default (scissors) path is present; the component never renders empty.
    expect($html)->toContain('<svg')->toContain('M7.848 8.25');
});

it('seeds the base catalogue with canonical icons', function () {
    $this->seed(DatabaseSeeder::class);

    app()->setLocale('ru');
    $byName = Service::all()->keyBy(fn (Service $s) => $s->name);

    expect($byName['Мужская стрижка']->icon)->toBe('scissors')
        ->and($byName['Чистка лица']->icon)->toBe('face-smile')
        ->and($byName['Укладка']->icon)->toBe('swatch');
});

it('backfills icons for existing catalogue rows on migration', function () {
    // A legacy row stored before the icon column existed.
    $id = DB::table('services')->insertGetId([
        'name' => Service::encodeTranslations(['ru' => 'Мужская стрижка', 'uz' => 'Soch olish (Erkaklar)', 'kaa' => 'Shash alıw (Er adamlar)']),
        'duration_minutes' => 45,
        'is_active' => true,
    ]);
    DB::table('services')->where('id', $id)->update(['icon' => null]);

    // Re-run the icon backfill (idempotent).
    (require database_path('migrations/2026_06_27_164759_add_icon_to_services_table.php'))->up();

    expect(Service::find($id)->icon)->toBe('scissors');
});
