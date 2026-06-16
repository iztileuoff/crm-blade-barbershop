<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the login screen for guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Вход');
});

it('shows the booking tab in the admin navigation', function () {
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Новая запись');
});

it('applies the stored theme before paint to avoid a flash', function () {
    $this->get(route('booking'))
        ->assertOk()
        ->assertSee("localStorage.getItem('theme')", escape: false)
        ->assertSee("document.documentElement.classList.add('dark')", escape: false);
});

it('keeps every theme toggle in sync via a shared event', function () {
    // Both toggles must broadcast and react to the same `theme-changed` event,
    // otherwise one toggle goes stale and the UI shows a mixed light/dark state.
    $this->get(route('booking'))
        ->assertOk()
        ->assertSee('@theme-changed.window', escape: false)
        ->assertSee("\$dispatch('theme-changed'", escape: false);
});

it('renders every admin page for a super admin', function (string $route) {
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route($route))
        ->assertOk();
})->with([
    'admin.dashboard',
    'admin.appointments',
    'admin.specializations',
    'admin.barbers',
    'admin.services',
    'admin.clients',
    'admin.products',
    'admin.orders',
    'admin.debts',
    'admin.sms.templates',
    'admin.sms.history',
    'admin.sms.settings',
    'admin.telegram.templates',
    'admin.telegram.broadcast',
    'admin.telegram.linked',
    'admin.settings',
    'admin.users',
    'booking',
]);
