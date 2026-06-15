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
    'admin.settings',
    'admin.users',
    'booking',
]);
