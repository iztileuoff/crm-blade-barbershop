<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function linkedAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

function telegramLinkedBarber(string $name, int $chatId): User
{
    return User::factory()->create([
        'name' => $name,
        'role' => Role::BARBER,
        'telegram_chat_id' => $chatId,
    ]);
}

it('paginates the linked list instead of materialising both tables', function () {
    Client::factory()->count(20)->sequence(fn ($s) => [
        'name' => 'Клиент '.str_pad((string) $s->index, 2, '0', STR_PAD_LEFT),
        'telegram_chat_id' => 1000 + $s->index,
    ])->create();

    for ($i = 0; $i < 10; $i++) {
        telegramLinkedBarber('Мастер '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 2000 + $i);
    }

    // Клиент без привязки в список попасть не должен.
    Client::factory()->create(['name' => 'Без Телеграма', 'telegram_chat_id' => null]);

    $page = Livewire::actingAs(linkedAdmin())->test('pages.admin.telegram.linked');

    expect($page->instance()->linked->total())->toBe(30)
        ->and($page->instance()->linked)->toHaveCount(25)
        ->and($page->instance()->clientsCount)->toBe(20)
        ->and($page->instance()->barbersCount)->toBe(10);

    $page->assertDontSee('Без Телеграма')
        ->call('gotoPage', 2)
        ->assertOk();
});

it('filters by link type', function () {
    Client::factory()->create(['name' => 'Клиент Али', 'telegram_chat_id' => 1001]);
    telegramLinkedBarber('Мастер Бек', 2001);

    $page = Livewire::actingAs(linkedAdmin())->test('pages.admin.telegram.linked');

    $page->call('setFilter', 'clients')
        ->assertSee('Клиент Али')
        ->assertDontSee('Мастер Бек');

    $page->call('setFilter', 'barbers')
        ->assertSee('Мастер Бек')
        ->assertDontSee('Клиент Али');

    $page->call('setFilter', 'all')
        ->assertSee('Клиент Али')
        ->assertSee('Мастер Бек');
});

it('ignores an unknown filter', function () {
    Livewire::actingAs(linkedAdmin())
        ->test('pages.admin.telegram.linked')
        ->call('setFilter', 'что-угодно')
        ->assertSet('filter', 'all');
});

it('searches by name and by the phone format the interface shows', function () {
    Client::factory()->create(['name' => 'Клиент Али', 'phone' => '998901234567', 'telegram_chat_id' => 1001]);
    telegramLinkedBarber('Мастер Бек', 2001)->update(['phone' => '998911112233']);

    $page = Livewire::actingAs(linkedAdmin())->test('pages.admin.telegram.linked');

    $page->set('search', 'Али');
    expect($page->instance()->linked->total())->toBe(1);

    $page->set('search', '+998 91 111 22 33');
    expect($page->instance()->linked->total())->toBe(1);
    $page->assertSee('Мастер Бек');
});

it('unlinks a client and a barber', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 1001]);
    $barber = telegramLinkedBarber('Мастер Бек', 2001);

    Livewire::actingAs(linkedAdmin())
        ->test('pages.admin.telegram.linked')
        ->call('unlinkClient', $client->id)
        ->call('unlinkBarber', $barber->id);

    expect($client->fresh()->telegram_chat_id)->toBeNull()
        ->and($barber->fresh()->telegram_chat_id)->toBeNull();
});
