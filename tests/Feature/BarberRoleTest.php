<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Create a today appointment for the given barber with a named client.
 * Built via the model (not the factory) because the factory still references
 * a removed service_id column.
 */
function appointmentFor(Barber $barber, string $clientName): Appointment
{
    return Appointment::create([
        'client_id' => Client::factory()->create(['name' => $clientName])->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(10, 0),
        'ends_at' => now()->setTime(11, 0),
    ]);
}

function barberUser(): array
{
    $user = User::factory()->create(['role' => Role::BARBER]);
    $barber = Barber::factory()->create(['user_id' => $user->id]);

    return [$user, $barber];
}

it('shows a barber only their own appointments', function () {
    [$user, $barber] = barberUser();
    $otherBarber = Barber::factory()->create();

    appointmentFor($barber, 'My Client');
    appointmentFor($otherBarber, 'Other Client');

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->assertSet('isBarberView', true)
        ->assertSet('ownBarberId', $barber->id)
        ->assertSee('My Client')
        ->assertDontSee('Other Client');
});

it('lets an admin see every barber appointment', function () {
    $admin = User::factory()->create(['role' => Role::ADMIN]);
    $barberA = Barber::factory()->create();
    $barberB = Barber::factory()->create();

    appointmentFor($barberA, 'Client A');
    appointmentFor($barberB, 'Client B');

    Livewire::actingAs($admin)
        ->test('pages.admin.appointments')
        ->assertSet('isBarberView', false)
        ->assertSee('Client A')
        ->assertSee('Client B');
});

it('forbids a barber from mutating appointments', function () {
    [$user, $barber] = barberUser();
    $appointment = appointmentFor($barber, 'My Client');

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('markCompleted', $appointment->id)
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->assertForbidden();
});

it('redirects a barber away from non-appointment admin pages', function () {
    [$user] = barberUser();

    $this->actingAs($user)
        ->get(route('admin.clients'))
        ->assertRedirect(route('admin.appointments'));

    $this->actingAs($user)
        ->get(route('admin.appointments'))
        ->assertOk();
});

it('links the chosen barber when saving a barber user', function () {
    $superAdmin = User::factory()->create(['role' => Role::SUPER_ADMIN]);
    $barber = Barber::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test('pages.admin.users')
        ->set('name', 'New Master')
        ->set('phone', '998901112233')
        ->set('password', 'secret123')
        ->set('role', 'barber')
        ->set('barberId', $barber->id)
        ->call('save')
        ->assertHasNoErrors();

    $created = User::where('name', 'New Master')->firstOrFail();

    expect($created->role)->toBe(Role::BARBER)
        ->and($barber->fresh()->user_id)->toBe($created->id);
});
