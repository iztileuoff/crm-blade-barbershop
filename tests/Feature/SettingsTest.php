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
