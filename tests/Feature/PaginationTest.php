<?php

use App\Enums\Role;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => Role::ADMIN]));

    // Страница SMS-истории режет по 25 — двух страниц достаточно, чтобы
    // пагинатор вообще отрисовался (`@if ($paginator->hasPages())`).
    SmsMessage::factory()->count(30)->create();
});

it('renders the paginator in the selected locale', function (string $locale) {
    $response = $this->withSession(['locale' => $locale])
        ->get(route('admin.sms.history'))
        ->assertOk()
        ->assertSee(__('pagination.previous', locale: $locale))
        ->assertSee(__('pagination.next', locale: $locale))
        ->assertSee(__('pagination.summary', ['first' => 1, 'last' => 25, 'total' => 30], $locale));

    // Вендорный шаблон отдавал английский на всех трёх локалях.
    foreach (['Showing', 'results', 'Pagination Navigation', 'Go to page'] as $english) {
        $response->assertDontSee($english);
    }
})->with(['ru', 'uz', 'kaa']);

it('styles the paginator with the design system, not the vendor greys', function () {
    $html = $this->get(route('admin.sms.history'))->assertOk()->getContent();

    expect($html)->toContain('border-content/[0.08]')
        ->and($html)->not->toContain('dark:bg-gray-800')
        ->and($html)->not->toContain('ring-blue-300');
});

it('walks to the second page', function () {
    Livewire\Livewire::test('pages.admin.sms.history')
        ->call('gotoPage', 2)
        ->assertOk()
        ->assertSee(__('pagination.summary', ['first' => 26, 'last' => 30, 'total' => 30]));
});
