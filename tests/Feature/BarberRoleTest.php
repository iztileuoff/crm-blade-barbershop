<?php

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('refuses to let the client rewrite the role properties at all', function () {
    // Первый рубеж: свойства заперты, подделать их запросом нельзя.
    [$user] = barberUser();

    foreach (['isBarberView', 'ownBarberId'] as $property) {
        expect(fn () => Livewire::actingAs($user)
            ->test('pages.admin.appointments')
            ->set($property, $property === 'isBarberView' ? false : 999)
        )->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

it('blocks every mutating method for a barber regardless of component state', function () {
    // Второй рубеж: страж берёт роль из сессии, а не из свойства.
    [$user, $barber] = barberUser();
    $appointment = appointmentFor($barber, 'My Client');

    foreach (['markCompleted', 'markConfirmed', 'markCancelled', 'delete', 'edit'] as $method) {
        Livewire::actingAs($user)
            ->test('pages.admin.appointments')
            ->call($method, $appointment->id)
            ->assertForbidden();
    }

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('save')
        ->assertForbidden();
});

it('never shows a barber another barber appointments even if the filter is forged', function () {
    [$user, $barber] = barberUser();
    $other = Barber::factory()->create(['name' => 'Someone Else']);

    appointmentFor($barber, 'My Client');
    appointmentFor($other, 'Their Client');

    // barberFilter не заперт — но выборка всё равно сужается по сессии.
    $rows = Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->set('barberFilter', $other->id)
        ->instance()
        ->appointments();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->barber_id)->toBe($barber->id);
});

it('never leaks the client directory to a barber through the form modal', function () {
    [$user] = barberUser();

    // Клиент, которого этот мастер никогда не обслуживал.
    Client::factory()->create(['name' => 'Секретный Клиент', 'phone' => '998901112233']);

    // showForm заперт — подделать его запросом нельзя.
    expect(fn () => Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->set('showForm', true)
    )->toThrow(CannotUpdateLockedPropertyException::class);

    // И даже если бы модалка открылась, читать клиентов мастеру нечем.
    $component = Livewire::actingAs($user)->test('pages.admin.appointments');

    expect($component->instance()->filteredClients())->toHaveCount(0)
        ->and($component->html())->not->toContain('998901112233')
        ->and($component->html())->not->toContain('Секретный Клиент');
});

it('forbids a barber from reaching the debts screen', function () {
    [$user] = barberUser();

    Livewire::actingAs($user)
        ->test('pages.admin.debts')
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
