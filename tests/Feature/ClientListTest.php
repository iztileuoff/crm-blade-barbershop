<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function clientListAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('paginates instead of silently cutting the list at 100 rows', function () {
    Client::factory()->count(30)->create();
    $oldest = Client::orderBy('id')->first();

    $list = Livewire::actingAs(clientListAdmin())->test('pages.admin.clients');

    expect($list->instance()->clients->total())->toBe(30)
        ->and($list->instance()->clients)->toHaveCount(25);

    // Клиент с самым маленьким id (сортировка — id desc) достижим со второй
    // страницы, а не только угадыванием поискового запроса.
    $list->assertDontSee($oldest->name)
        ->call('gotoPage', 2)
        ->assertSee($oldest->name);
});

it('finds a phone pasted in the format the interface shows', function () {
    $client = Client::factory()->create(['name' => 'Али', 'phone' => '998901234567']);
    Client::factory()->create(['name' => 'Бек', 'phone' => '998911112233']);

    $list = Livewire::actingAs(clientListAdmin())->test('pages.admin.clients');

    foreach (['+998 90 123 45 67', '998901234567', '90 123 45 67', '901234567'] as $term) {
        $list->set('search', $term);

        expect($list->instance()->clients->pluck('id')->all())
            ->toBe([$client->id], "поиск по «{$term}»");
    }
});

it('still finds a client by name', function () {
    $client = Client::factory()->create(['name' => 'Али Валиев']);
    Client::factory()->create(['name' => 'Бек Юсупов']);

    $list = Livewire::actingAs(clientListAdmin())
        ->test('pages.admin.clients')
        ->set('search', 'Вали');

    expect($list->instance()->clients->pluck('id')->all())->toBe([$client->id]);
});

it('goes back to the first page when the search changes', function () {
    Client::factory()->count(60)->create();

    Livewire::actingAs(clientListAdmin())
        ->test('pages.admin.clients')
        ->call('gotoPage', 3)
        ->assertSet('paginators.page', 3)
        ->set('search', 'кто-нибудь')
        ->assertSet('paginators.page', 1);
});

it('ignores a digit fragment too short to be a phone', function () {
    Client::factory()->create(['name' => 'Али 2', 'phone' => '998902222222']);
    Client::factory()->create(['name' => 'Бек', 'phone' => '998912222222']);

    $list = Livewire::actingAs(clientListAdmin())
        ->test('pages.admin.clients')
        ->set('search', 'Али 2');

    // Одна цифра из имени не должна подтягивать чужие номера.
    expect($list->instance()->clients->pluck('name')->all())->toBe(['Али 2']);
});
