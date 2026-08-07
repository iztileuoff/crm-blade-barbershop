<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the login screen for guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Вход');
});

it('shows the booking tab in the admin navigation', function () {
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Новая запись');
});

it('includes the offline indicator markup and its translated strings in the admin layout', function () {
    // Issue #75: the offline strip, the transport-error banner and the
    // session-expired modal all live in the shared admin layout, not on any
    // one page — resources/js/app.js dispatches the `transport-*` window
    // events these listeners react to, and carries no translated text of
    // its own, so the strings must come from the markup itself.
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('x-on:offline.window', escape: false)
        ->assertSee('x-on:online.window', escape: false)
        ->assertSee('transport-error.window', escape: false)
        ->assertSee('transport-session-expired.window', escape: false)
        ->assertSee(__('errors.offline_indicator'))
        ->assertSee(__('errors.connection_lost_title'))
        ->assertSee(__('errors.connection_lost_body'))
        ->assertSee(__('errors.session_expired_title'))
        ->assertSee(__('errors.session_expired_body'))
        ->assertSee(route('login'), escape: false);
});

it('applies the stored theme before paint to avoid a flash', function () {
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route('booking'))
        ->assertOk()
        ->assertSee("localStorage.getItem('theme')", escape: false)
        ->assertSee("document.documentElement.classList.add('dark')", escape: false);
});

it('keeps every theme toggle in sync via a shared event', function () {
    // Both toggles must broadcast and react to the same `theme-changed` event,
    // otherwise one toggle goes stale and the UI shows a mixed light/dark state.
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route('booking'))
        ->assertOk()
        ->assertSee('@theme-changed.window', escape: false)
        ->assertSee("\$dispatch('theme-changed'", escape: false);
});

it('restores the theme after a wire:navigate wipes it off the html element', function () {
    // Livewire переписывает атрибуты <html> серверной разметкой (replaceHtmlAttributes),
    // а сервер про тему не знает — класс `dark` стирается на каждом переходе по меню,
    // и инлайновый скрипт из <head> его не вернёт: mergeNewHead не перезапускает уже
    // стоящие теги. Значит, восстановление обязано жить в app.js (issue #95).
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toContain("document.addEventListener('livewire:navigated'")
        ->toContain("document.documentElement.classList.toggle('dark'")
        // Та же логика выбора, что и у инлайнового скрипта, иначе тема после перехода
        // разойдётся с темой после F5.
        ->toContain("localStorage.getItem('theme')")
        ->toContain("'(prefers-color-scheme: dark)'")
        // `livewire:navigated` приходит после инициализации Alpine, поэтому свежий
        // переключатель уже прочитал <html> без класса — его нужно досинхронизировать
        // тем же событием, что и обычное переключение.
        ->toContain("new CustomEvent('theme-changed', { detail: { dark } })");
});

it('renders every admin page for a super admin', function (string $route) {
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route($route))
        ->assertOk();
})->with([
    'admin.dashboard',
    'admin.appointments',
    'admin.specializations',
    'admin.barbers',
    'admin.services',
    'admin.clients',
    'admin.products',
    'admin.orders',
    'admin.debts',
    'admin.sms.templates',
    'admin.sms.history',
    'admin.sms.settings',
    'admin.telegram.templates',
    'admin.telegram.broadcast',
    'admin.telegram.linked',
    'admin.settings',
    'admin.users',
    'booking',
]);
