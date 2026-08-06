<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function clientAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

/**
 * Build a client with a full history: completed + cancelled appointments,
 * an order with items, and an SMS message.
 *
 * @return array{client: Client, barber: Barber, service: Service, product: Product}
 */
function clientWithHistory(): array
{
    $client = Client::factory()->create(['name' => 'Тест Клиент']);
    $barber = Barber::factory()->create(['name' => 'Иван Мастер']);
    $service = Service::factory()->create(['name' => 'Мужская стрижка']);

    $completed = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->subDays(5)->setTime(10, 0),
        'ends_at' => now()->subDays(5)->setTime(11, 0),
        'status' => AppointmentStatus::Completed,
        'price' => 50000,
        'debt_amount' => 5000,
    ]);
    $completed->services()->attach($service->id, ['amount' => 50000]);

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->subDays(2)->setTime(10, 0),
        'ends_at' => now()->subDays(2)->setTime(11, 0),
        'status' => AppointmentStatus::Cancelled,
    ]);

    $product = Product::create(['name' => 'Помада для волос', 'stock' => 10, 'selling_price' => 30000]);
    $order = Order::create(['client_id' => $client->id, 'total_price' => 30000, 'debt_amount' => 10000]);
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price_at_sale' => 30000,
    ]);

    SmsMessage::factory()->create([
        'client_id' => $client->id,
        'message' => 'Ваша запись завтра в 10:00',
        'context' => 'reminder',
        'status' => 'sent',
    ]);

    return compact('client', 'barber', 'service', 'product');
}

it('renders the client profile with history data', function () {
    ['client' => $client] = clientWithHistory();

    // Вкладки серверные: в HTML уезжает только открытая история.
    $card = Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->assertOk()
        ->assertSee('Тест Клиент')
        ->assertSee('Иван Мастер')
        ->assertSee('Мужская стрижка')
        ->assertDontSee('Помада для волос')
        ->assertDontSee('Ваша запись завтра в 10:00');

    $card->call('showTab', 'orders')
        ->assertSee('Помада для волос')
        ->assertDontSee('Ваша запись завтра в 10:00');

    $card->call('showTab', 'sms')
        ->assertSee('Ваша запись завтра в 10:00')
        ->assertDontSee('Помада для волос');
});

it('ignores an unknown tab', function () {
    ['client' => $client] = clientWithHistory();

    Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->call('showTab', 'что-угодно')
        ->assertSet('tab', 'appointments');
});

it('cuts the history and loads more on demand', function () {
    $client = Client::factory()->create();
    $barber = Barber::factory()->create();

    for ($i = 0; $i < 25; $i++) {
        Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'starts_at' => now()->subDays($i)->setTime(10, 0),
            'ends_at' => now()->subDays($i)->setTime(11, 0),
            'status' => AppointmentStatus::Completed,
            'price' => 1000,
        ]);
    }

    $card = Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client]);

    expect($card->instance()->appointmentHistory)->toHaveCount(20)
        ->and($card->instance()->hasMoreHistory)->toBeTrue();

    $card->call('showMoreHistory');

    expect($card->instance()->appointmentHistory)->toHaveCount(25)
        ->and($card->instance()->hasMoreHistory)->toBeFalse();
});

it('clamps a crafted history limit', function () {
    $client = Client::factory()->create();
    $barber = Barber::factory()->create();

    for ($i = 0; $i < 25; $i++) {
        Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'starts_at' => now()->subDays($i)->setTime(10, 0),
            'ends_at' => now()->subDays($i)->setTime(11, 0),
            'status' => AppointmentStatus::Completed,
            'price' => 1000,
        ]);
    }

    // `historyLimit` — публичное свойство, то есть клиентское: и отрицательное,
    // и огромное значение должны упереться в границы, а не в базу.
    $card = Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->set('historyLimit', -5)
        ->assertOk();

    expect($card->instance()->appointmentHistory)->toHaveCount(20);
});

it('edits the client card in place', function () {
    $client = Client::factory()->create(['name' => 'Старое Имя', 'phone' => '998901112233']);

    Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->call('edit')
        ->assertSet('editing', true)
        ->assertSet('name', 'Старое Имя')
        ->set('name', 'Новое Имя')
        ->set('birth_date', '1990-05-15')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('editing', false)
        ->assertDispatched('profile-saved')
        ->assertSee('Новое Имя');

    $client->refresh();

    expect($client->name)->toBe('Новое Имя')
        ->and($client->birth_date->toDateString())->toBe('1990-05-15');
});

it('refuses to move a phone onto another client', function () {
    $client = Client::factory()->create(['phone' => '998901112233']);
    Client::factory()->create(['phone' => '998909998877']);

    Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->call('edit')
        ->set('phone', '+998 90 999 88 77')
        ->call('saveProfile')
        ->assertHasErrors('phone');

    expect($client->fresh()->phone)->toBe('998901112233');
});

it('opens the appointment form with the client already attached', function () {
    $client = Client::factory()->create(['name' => 'Али Валиев']);

    Livewire::actingAs(clientAdmin())
        ->withQueryParams(['client' => $client->id])
        ->test('pages.admin.appointments')
        ->assertSet('client_id', $client->id)
        ->assertSet('showForm', true)
        ->assertSeeHtml('wire:click="clearClient"');
});

it('computes client metrics correctly', function () {
    ['client' => $client, 'barber' => $barber] = clientWithHistory();

    $component = Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->instance();

    expect($component->visitsCount())->toBe(1)
        ->and($component->cancelledCount())->toBe(1)
        ->and($component->totalSpent())->toBe(80000)        // 50000 appointment + 30000 order
        ->and($component->totalDebt())->toBe(15000)         // 5000 appointment + 10000 order
        ->and($component->transactionsCount())->toBe(2)
        ->and($component->averageCheck())->toBe(40000)
        ->and($component->favoriteBarber()?->id)->toBe($barber->id)
        ->and($component->topServices()->first()->name)->toBe('Мужская стрижка');
});

it('saves client notes', function () {
    $client = Client::factory()->create();

    Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->set('notes', 'VIP клиент, любит кофе')
        ->call('saveNotes')
        ->assertHasNoErrors()
        ->assertDispatched('notes-saved');

    expect($client->fresh()->notes)->toBe('VIP клиент, любит кофе');
});

it('shows empty metrics for a client without history', function () {
    $client = Client::factory()->create();

    $component = Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->instance();

    expect($component->visitsCount())->toBe(0)
        ->and($component->totalSpent())->toBe(0)
        ->and($component->averageCheck())->toBe(0)
        ->and($component->favoriteBarber())->toBeNull();
});

it('links each client to its profile page from the list', function () {
    $client = Client::factory()->create();

    Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients')
        ->assertSee(route('admin.clients.show', $client), false);
});

it('returns 404 for a missing client', function () {
    $this->actingAs(clientAdmin())
        ->get('/admin/clients/999999')
        ->assertNotFound();
});
