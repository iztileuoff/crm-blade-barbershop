<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs out via POST and redirects to login', function () {
    $user = User::factory()->create(['role' => Role::ADMIN]);

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('rejects logging out via GET to prevent CSRF logout', function () {
    $user = User::factory()->create(['role' => Role::ADMIN]);

    $this->actingAs($user)
        ->get('/logout')
        ->assertStatus(405);

    $this->assertAuthenticated();
});
