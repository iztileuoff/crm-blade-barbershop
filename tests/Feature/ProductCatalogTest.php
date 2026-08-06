<?php

use App\Enums\Role;
use App\Models\Barber;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function catalogAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

function product(string $name, int $stock = 10): Product
{
    return Product::create(['name' => $name, 'stock' => $stock, 'selling_price' => 30000]);
}

it('paginates the product catalogue', function () {
    for ($i = 0; $i < 30; $i++) {
        product('Товар '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    $page = Livewire::actingAs(catalogAdmin())->test('pages.admin.products');

    expect($page->instance()->products)->toHaveCount(25)
        ->and($page->instance()->products->total())->toBe(30);
});

it('searches by name and resets the page', function () {
    product('Шампунь мятный');
    product('Воск для укладки');

    $page = Livewire::actingAs(catalogAdmin())
        ->test('pages.admin.products')
        ->set('search', 'Шампунь');

    expect($page->instance()->products->pluck('name')->all())->toBe(['Шампунь мятный']);
});

it('filters by stock level', function () {
    product('Много', 20);
    product('Мало', 3);
    product('Нет', 0);

    $page = Livewire::actingAs(catalogAdmin())->test('pages.admin.products');

    expect($page->instance()->lowStockCount)->toBe(1)
        ->and($page->instance()->outOfStockCount)->toBe(1);

    $page->call('setStockFilter', 'low');
    expect($page->instance()->products->pluck('name')->all())->toBe(['Мало']);

    $page->call('setStockFilter', 'none');
    expect($page->instance()->products->pluck('name')->all())->toBe(['Нет']);

    $page->call('setStockFilter', 'что-угодно')->assertSet('stockFilter', 'all');
});

it('receives a batch as a single delta', function () {
    $item = product('Шампунь', 4);

    // Партия в 30 бутылок — одна операция, а не 30 тапов по «+1».
    Livewire::actingAs(catalogAdmin())
        ->test('pages.admin.products')
        ->set('receiveQty.'.$item->id, 30)
        ->call('receiveStock', $item->id)
        ->assertHasNoErrors()
        ->assertSet('receiveQty.'.$item->id, null);

    expect($item->fresh()->stock)->toBe(34);
});

it('refuses a batch quantity that is not a sane number', function () {
    $item = product('Шампунь', 4);

    $page = Livewire::actingAs(catalogAdmin())->test('pages.admin.products');

    foreach ([0, -5, 100000] as $bad) {
        $page->set('receiveQty.'.$item->id, $bad)
            ->call('receiveStock', $item->id)
            ->assertHasErrors('receiveQty.'.$item->id);
    }

    expect($item->fresh()->stock)->toBe(4);
});

it('moves stock by a delta so parallel sales are not overwritten', function () {
    $item = product('Шампунь', 10);

    $page = Livewire::actingAs(catalogAdmin())->test('pages.admin.products');

    // Пока админ набирал приёмку, две штуки продали.
    $item->update(['stock' => 8]);

    $page->set('receiveQty.'.$item->id, 5)->call('receiveStock', $item->id);

    expect($item->fresh()->stock)->toBe(13);
});

it('hides deactivated services and barbers until asked', function () {
    Service::factory()->create(['name' => Service::encodeTranslations(['ru' => 'Живая услуга']), 'is_active' => true]);
    Service::factory()->create(['name' => Service::encodeTranslations(['ru' => 'Мёртвая услуга']), 'is_active' => false]);

    Barber::factory()->create(['name' => 'Живой Мастер', 'is_active' => true]);
    Barber::factory()->create(['name' => 'Уволенный Мастер', 'is_active' => false]);

    $services = Livewire::actingAs(catalogAdmin())->test('pages.admin.services');
    $services->assertSee('Живая услуга')->assertDontSee('Мёртвая услуга');
    $services->set('showInactive', true)->assertSee('Мёртвая услуга');

    $barbers = Livewire::actingAs(catalogAdmin())->test('pages.admin.barbers');
    $barbers->assertSee('Живой Мастер')->assertDontSee('Уволенный Мастер');
    $barbers->set('showInactive', true)->assertSee('Уволенный Мастер');
});
