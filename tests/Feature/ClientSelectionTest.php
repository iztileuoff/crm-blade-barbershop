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
        ->call('selectClient', $ali->id)
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
        ->call('selectClient', $ali->id)
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
        ->call('selectClient', $ali->id)
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

/*
|--------------------------------------------------------------------------
| Контракт onSelect(id) и подпись поля (#77)
|--------------------------------------------------------------------------
*/

it('builds the search text from the record, not from what the browser sent', function (string $component) {
    // Компонент передаёт только id: подпись раньше приезжала с клиента вместе
    // с ним, и текст в поле мог описывать не того, кого выбрали.
    $ali = Client::factory()->create(['name' => 'Али Валиев', 'phone' => '998901112233']);

    Livewire::actingAs(selectionAdmin())
        ->test($component)
        ->call('selectClient', $ali->id)
        ->assertSet('client_id', $ali->id)
        ->assertSet('clientSearch', 'Али Валиев (998901112233)');
})->with('client pickers');

it('ignores a client id that does not exist', function (string $component) {
    Livewire::actingAs(selectionAdmin())
        ->test($component)
        ->call('selectClient', 999999)
        ->assertSet('client_id', null)
        ->assertSet('clientSearch', '');
})->with('client pickers');

it('passes only the id to the picker, never a label', function () {
    $markup = file_get_contents(resource_path('views/components/search-select.blade.php'));

    expect($markup)->toContain('wire:click="{{ $onSelect }}({{ $option->id }})"');
});

it('ties its own label to its own input', function (string $component) {
    Client::factory()->create();

    $inputId = $component === 'pages.admin.appointments' ? 'appointment-client' : 'order-client';

    Livewire::actingAs(selectionAdmin())
        ->test($component)
        ->call('openCreate')
        ->assertSeeHtml('for="'.$inputId.'"')
        ->assertSeeHtml('id="'.$inputId.'"')
        // Видимая подпись есть — aria-label перекрыл бы её и разошёлся с ней.
        ->assertDontSeeHtml('aria-label="'.__('common.search').'"')
        ->assertSeeHtml('aria-controls="'.$inputId.'-list"');
})->with('client pickers');
