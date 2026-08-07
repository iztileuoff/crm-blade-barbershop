<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function settingsAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('saves the working hours', function () {
    Livewire::actingAs(settingsAdmin())
        ->test('pages.admin.settings')
        ->set('work_start', '10:00')
        ->set('work_end', '20:00')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('work_start'))->toBe('10:00')
        ->and(Setting::get('work_end'))->toBe('20:00');
});

it('refuses a closing time that is not after the opening time', function () {
    Livewire::actingAs(settingsAdmin())
        ->test('pages.admin.settings')
        ->set('work_start', '20:00')
        ->set('work_end', '09:00')
        ->call('save')
        ->assertHasErrors('work_end');

    expect(Setting::get('work_start'))->toBeNull()
        ->and(Setting::get('work_end'))->toBeNull();
});

it('refuses a closing time equal to the opening time', function () {
    Livewire::actingAs(settingsAdmin())
        ->test('pages.admin.settings')
        ->set('work_start', '10:00')
        ->set('work_end', '10:00')
        ->call('save')
        ->assertHasErrors('work_end');

    expect(Setting::get('work_end'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Telegram-хендл салона (#92)
|--------------------------------------------------------------------------
*/

it('stores a pasted telegram url as a bare handle', function () {
    Livewire::actingAs(settingsAdmin())
        ->test('pages.admin.settings')
        ->set('telegram', 'https://t.me/blade_barbershop')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('telegram'))->toBe('blade_barbershop')
        ->and(Setting::telegramUrl())->toBe('https://t.me/blade_barbershop');
});

it('strips a leading at sign from the handle', function () {
    Livewire::actingAs(settingsAdmin())
        ->test('pages.admin.settings')
        ->set('telegram', '@blade_barbershop')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('telegram'))->toBe('blade_barbershop');
});

it('refuses a telegram handle that is not a handle at all', function () {
    Livewire::actingAs(settingsAdmin())
        ->test('pages.admin.settings')
        ->set('telegram', 'наш телеграм — спросите у администратора')
        ->call('save')
        ->assertHasErrors('telegram');

    expect(Setting::get('telegram'))->toBeNull();
});

it('builds the bot link in exactly one place', function () {
    // Раньше ссылку независимо собирали макет публичной брони и компонент
    // записи, и вставленный целиком адрес давал t.me/https://t.me/...
    Setting::set('telegram', 'https://t.me/blade_barbershop');

    expect(Setting::telegramUrl())->toBe('https://t.me/blade_barbershop');

    $this->get(route('booking'))
        ->assertOk()
        ->assertSee('https://t.me/blade_barbershop', escape: false)
        ->assertDontSee('t.me/https', escape: false);
});

it('has no bot link at all when the handle is empty', function () {
    Setting::set('telegram', null);

    expect(Setting::telegramUrl())->toBeNull();
});
