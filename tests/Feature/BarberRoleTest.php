<?php

use App\Enums\AppointmentStatus;
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

it('forbids a barber from creating appointments', function () {
    [$user] = barberUser();

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->assertForbidden();
});

// Issue #74: the blanket ban on status changes is gone — a barber may now
// change the status of THEIR OWN appointments. CRUD (openCreate/edit/delete/
// save) stays admin-only, covered above and below.
it('lets a barber change the status of their own appointment', function () {
    [$user, $barber] = barberUser();
    $appointment = appointmentFor($barber, 'My Client');

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('markCompleted', $appointment->id)
        ->assertOk();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed);
});

it('lets a barber confirm and cancel their own appointment too, not just complete it', function () {
    [$user, $barber] = barberUser();

    $toConfirm = appointmentFor($barber, 'Confirm Me');
    $toCancel = appointmentFor($barber, 'Cancel Me');

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('markConfirmed', $toConfirm->id)
        ->assertOk()
        ->call('markCancelled', $toCancel->id)
        ->assertOk();

    expect($toConfirm->fresh()->status)->toBe(AppointmentStatus::Confirmed)
        ->and($toCancel->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('forbids a barber from changing another barber appointment status, even after forging the filter or the locked role properties', function () {
    [$user] = barberUser();
    $other = Barber::factory()->create();
    $theirAppointment = appointmentFor($other, 'Their Client');

    // Попытка №1: подделать незапертый barberFilter — источник прав не он, а сессия.
    // Свежий инстанс на каждый вызов: после 403 снимок компонента не переиспользуется.
    foreach (['markConfirmed', 'markCompleted', 'markCancelled'] as $method) {
        Livewire::actingAs($user)
            ->test('pages.admin.appointments')
            ->set('barberFilter', $other->id)
            ->call($method, $theirAppointment->id)
            ->assertForbidden();
    }

    // Попытка №2: подделать запертые isBarberView/ownBarberId — Livewire не даёт вовсе.
    expect(fn () => Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->set('ownBarberId', $other->id)
    )->toThrow(CannotUpdateLockedPropertyException::class);

    expect($theirAppointment->fresh()->status)->toBe(AppointmentStatus::Pending);
});

it('answers a barber the same way for a foreign appointment and a missing one', function () {
    // Разница между 403 и 404 сама по себе выдаёт, какие id заняты: перебором
    // мастер вычитал бы всю книгу записей салона, не видя ни одной строки.
    [$user] = barberUser();
    $foreign = appointmentFor(Barber::factory()->create(), 'Чужой Клиент');

    foreach ([$foreign->id, 999999] as $id) {
        Livewire::actingAs($user)
            ->test('pages.admin.appointments')
            ->call('markCompleted', $id)
            ->assertForbidden();
    }
});

it('does not repeat a status change that changes nothing', function () {
    // На отмене висит уведомление клиенту: повторный перевод в тот же статус
    // не должен слать его снова.
    [$user, $barber] = barberUser();
    $appointment = appointmentFor($barber, 'Постоянный Клиент');
    $appointment->update(['status' => AppointmentStatus::Cancelled]);
    $updatedAt = $appointment->fresh()->updated_at;

    $this->travelTo(now()->addMinute());

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->call('markCancelled', $appointment->id)
        ->assertOk();

    expect($appointment->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
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

it('blocks every CRUD method for a barber regardless of component state', function () {
    // Второй рубеж: страж берёт роль из сессии, а не из свойства. Деньги и
    // удаление остаются админскими даже для собственной записи мастера.
    [$user, $barber] = barberUser();
    $appointment = appointmentFor($barber, 'My Client');

    foreach (['delete', 'edit'] as $method) {
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

it('redirects a barber away from non-appointment admin pages but lets them through to their earnings', function () {
    [$user] = barberUser();

    $this->actingAs($user)
        ->get(route('admin.clients'))
        ->assertRedirect(route('admin.appointments'));

    $this->actingAs($user)
        ->get(route('admin.appointments'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('admin.earnings'))
        ->assertOk();
});

it('flags the appointments page with a one-shot banner when a barber is redirected off a restricted page', function () {
    [$user] = barberUser();

    $this->actingAs($user)
        ->followingRedirects()
        ->get(route('admin.debts'))
        ->assertOk()
        ->assertSee(__('appointments.restricted_banner'));

    // Одноразовый: обычный визит без предшествующего редиректа баннер не показывает.
    $this->actingAs($user)
        ->get(route('admin.appointments'))
        ->assertOk()
        ->assertDontSee(__('appointments.restricted_banner'));
});

it('lets the barber dismiss the restricted-section banner', function () {
    [$user] = barberUser();
    session(['barberRestricted' => true]);

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->assertSee(__('appointments.restricted_banner'))
        ->call('dismissBarberRestrictedBanner')
        ->assertDontSee(__('appointments.restricted_banner'));
});

it('hides the barber column from a barber own table but keeps it for an admin', function () {
    [$user, $barber] = barberUser();
    appointmentFor($barber, 'My Client');

    Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->assertDontSee(__('common.barber'));

    $admin = User::factory()->create(['role' => Role::ADMIN]);

    Livewire::actingAs($admin)
        ->test('pages.admin.appointments')
        ->assertSee(__('common.barber'));
});

it('shows an explicit empty state and no appointments for a barber with no linked profile', function () {
    // Роль есть, а профиля мастера (barbers.user_id) нет — уникальная утечка из #74.
    $user = User::factory()->create(['role' => Role::BARBER]);
    $barberA = Barber::factory()->create();
    $barberB = Barber::factory()->create();

    appointmentFor($barberA, 'Client A');
    appointmentFor($barberB, 'Client B');

    $component = Livewire::actingAs($user)
        ->test('pages.admin.appointments')
        ->assertDontSee('Client A')
        ->assertDontSee('Client B')
        ->assertSee(__('appointments.barber_unlinked'));

    expect($component->instance()->appointments())->toHaveCount(0);
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
