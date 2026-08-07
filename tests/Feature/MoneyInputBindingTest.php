<?php

use App\Enums\Role;
use App\Models\Barber;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Числовые поля формы, которые уходят на сервер по ходу набора, но не
 * объявлены как число.
 *
 * Без .number браузер отправляет строку «12», типизированное свойство
 * возвращается числом 12, и Livewire принимает смену типа за правку значения
 * на сервере: он патчит поле ответом и стирает цифры, набранные за время
 * запроса. Набранное «120» на глазах откатывалось в «12».
 *
 * @return array<int, string>
 */
function liveNumberInputsMissingNumberModifier(string $html): array
{
    preg_match_all('/<input\b[^>]*>/i', $html, $matches);

    return collect($matches[0])
        ->filter(fn (string $tag): bool => str_contains($tag, 'type="number"'))
        ->filter(fn (string $tag): bool => (bool) preg_match('/wire:model[^=]*\.live/', $tag))
        ->reject(fn (string $tag): bool => (bool) preg_match('/wire:model[^=]*\.number/', $tag))
        ->values()
        ->all();
}

function moneyInputAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('spots a live money field that lost its number modifier', function () {
    // Иначе обе проверки ниже проходили бы на любой разметке.
    $bad = '<input type="number" wire:model.live="cash_amount" min="0">';
    $good = '<input type="number" wire:model.number.live="cash_amount" min="0">';

    expect(liveNumberInputsMissingNumberModifier($bad))->toBe([$bad])
        ->and(liveNumberInputsMissingNumberModifier($good))->toBe([]);
});

it('never lets the appointment form rewrite a sum while the admin is still typing it', function () {
    $barber = Barber::factory()->create(['salary_percent' => 50, 'price' => 100000]);
    $service = Service::factory()->create();

    $html = Livewire::actingAs(moneyInputAdmin())
        ->test('pages.admin.appointments')
        ->call('openCreate')
        ->set('barber_id', $barber->id)
        ->set('payment_type', 'both')
        ->set('debtEnabled', true)
        ->set('selectedServices', [['service_id' => $service->id, 'amount' => 100000]])
        ->html();

    // Все четыре денежных поля формы должны быть на экране, иначе проверка
    // ниже пройдёт впустую.
    expect($html)
        ->toContain('wire:model.number.live="cash_amount"')
        ->toContain('wire:model.number.live="card_amount"')
        ->toContain('wire:model.number.live="debt_amount"')
        ->toContain('wire:model.number.live="selectedServices.0.amount"');

    expect(liveNumberInputsMissingNumberModifier($html))->toBe([]);
});

it('never lets the sale form rewrite a sum while the cashier is still typing it', function () {
    $product = Product::create([
        'name' => 'Гель',
        'selling_price' => 50000,
        'purchase_price' => 30000,
        'stock' => 10,
        'is_active' => true,
    ]);

    $html = Livewire::actingAs(moneyInputAdmin())
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->call('addToCart', $product->id)
        ->set('payment_type', 'both')
        ->set('debtEnabled', true)
        ->html();

    expect($html)
        ->toContain('wire:model.number.live.debounce.500ms="cash_amount"')
        ->toContain('wire:model.number.live.debounce.500ms="card_amount"')
        ->toContain('wire:model.number.live="debt_amount"');

    expect(liveNumberInputsMissingNumberModifier($html))->toBe([]);
});
