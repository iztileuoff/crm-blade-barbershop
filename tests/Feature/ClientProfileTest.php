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
    ['client' => $client, 'barber' => $barber] = clientWithHistory();

    Livewire::actingAs(clientAdmin())
        ->test('pages.admin.clients.show', ['client' => $client])
        ->assertOk()
        ->assertSee('Тест Клиент')
        ->assertSee('Иван Мастер')
        ->assertSee('Мужская стрижка')
        ->assertSee('Помада для волос')
        ->assertSee('Ваша запись завтра в 10:00');
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
