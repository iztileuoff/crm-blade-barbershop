<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function selectionAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

dataset('client pickers', [
    'appointments' => ['pages.admin.appointments'],
    'orders' => ['pages.admin.orders'],
]);

it('drops the confirmed client when the search text is edited', function (string $component) {
    $ali = Client::factory()->create(['name' => 'Али']);
    Client::factory()->create(['name' => 'Бек']);

    Livewire::actingAs(selectionAdmin())
        ->test($component)
        ->call('selectClient', $ali->id, 'Али')
        ->assertSet('client_id', $ali->id)
        // Оператор стёр «Али» и набрал «Бек» — привязка к Али должна умереть
        // вместе с текстом, из которого она взялась.
        ->set('clientSearch', 'Бек')
        ->assertSet('client_id', null);
})->with('client pickers');

it('clears the client through the chip', function (string $component) {
    $ali = Client::factory()->create(['name' => 'Али']);

    Livewire::actingAs(selectionAdmin())
        ->test($component)
        ->call('selectClient', $ali->id, 'Али')
        ->assertSet('client_id', $ali->id)
        ->call('clearClient')
        ->assertSet('client_id', null)
        ->assertSet('clientSearch', '');
})->with('client pickers');

it('shows the chip only once a client is really attached', function (string $component) {
    $ali = Client::factory()->create(['name' => 'Али Валиев']);

    // Имя клиента само по себе видно и в выпадающем списке — о привязке
    // говорит именно чип с крестиком.
    Livewire::actingAs(selectionAdmin())
        ->test($component)
        ->call('openCreate')
        ->assertDontSeeHtml('wire:click="clearClient"')
        ->call('selectClient', $ali->id, 'Али Валиев')
        ->assertSeeHtml('wire:click="clearClient"')
        ->set('clientSearch', 'Бек')
        ->assertDontSeeHtml('wire:click="clearClient"');
})->with('client pickers');

/*
|--------------------------------------------------------------------------
| Клавиатура выпадашки (#90)
|--------------------------------------------------------------------------
*/

it('opens the list on arrow up, like it already did on arrow down', function () {
    // Раньше ↑ зажимался в 0 и список не открывал: одно нажатие подсвечивало
    // первый вариант при закрытой выпадашке, и следующий Enter выбирал
    // клиента, которого пользователь не видел.
    $markup = file_get_contents(resource_path('views/components/search-select.blade.php'));

    expect($markup)
        ->toContain('x-on:keydown.up.prevent="open = true; highlighted = Math.max(highlighted - 1, -1)"')
        ->toContain('x-on:keydown.down.prevent="open = true;');
});

it('renders that arrow-up handler wherever a client is picked', function (string $component) {
    Client::factory()->create(['name' => 'Али']);

    // Выпадашка живёт внутри модалки — до openCreate() её в разметке нет.
    Livewire::actingAs(selectionAdmin())
        ->test($component)
        ->call('openCreate')
        ->assertSee('open = true; highlighted = Math.max(highlighted - 1, -1)', escape: false);
})->with('client pickers');

it('never confirms a selection while the list is closed', function () {
    // Enter стреляет только при open && highlighted >= 0 — вернуться к
    // «ничего не выбрано» теперь возможно, поэтому граница именно -1.
    $markup = file_get_contents(resource_path('views/components/search-select.blade.php'));

    expect($markup)->toContain('if (open && highlighted >= 0)');
});
