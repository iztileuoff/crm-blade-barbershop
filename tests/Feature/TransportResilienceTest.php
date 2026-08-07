<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => Role::SUPER_ADMIN]));
});

it('renders the offline strip, the transport-error banner and the session-expired modal on every admin page', function () {
    $this->get(route('admin.dashboard'))
        ->assertOk()
        // Полоска офлайна — на Alpine, а не на wire:offline: разметка живёт в
        // макете, вне Livewire-компонента, где директива не обрабатывается.
        ->assertSee('x-on:offline.window', escape: false)
        ->assertSee('x-on:online.window', escape: false)
        ->assertSee('transport-error.window', escape: false)
        ->assertSee('transport-session-expired.window', escape: false)
        ->assertSee(__('errors.offline_indicator'))
        ->assertSee(__('errors.connection_lost_title'))
        ->assertSee(__('errors.session_expired_title'))
        ->assertSee(route('login'), escape: false);
});

it('shows a guest on the public booking page the same transport feedback', function () {
    // app.js гасит собственную реакцию Livewire на упавший запрос на каждой
    // странице, где он подключён. Без этого компонента гость не увидел бы ни
    // обрыва связи, ни истёкшей сессии — кнопка просто выглядела бы мёртвой.
    auth()->logout();

    $this->get(route('booking'))
        ->assertOk()
        ->assertSee('transport-error.window', escape: false)
        ->assertSee('transport-session-expired.window', escape: false)
        ->assertSee(__('errors.connection_lost_title'))
        // Гостю предлагаем обновить страницу: сессии, в которую можно «войти
        // заново», у него нет, а 419 там — протухший CSRF-токен.
        ->assertSee(__('errors.session_expired_body_guest'))
        ->assertDontSee(__('errors.login_again'));
});

it('shows the same transport feedback on the login page', function () {
    auth()->logout();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('transport-error.window', escape: false)
        ->assertSee('transport-session-expired.window', escape: false)
        ->assertSee(__('errors.connection_lost_title'));
});

it('never loads app.js in a layout that cannot report the failure it swallows', function () {
    // Связка «app.js + x-transport-status» разъезжалась молча: подключение
    // JS есть, слушателя нет — и обратная связь пропадает целиком.
    $layouts = glob(resource_path('views/components/layouts/*.blade.php'));

    expect($layouts)->not->toBeEmpty();

    foreach ($layouts as $layout) {
        $markup = file_get_contents($layout);

        if (! str_contains($markup, 'resources/js/app.js')) {
            continue;
        }

        expect($markup)->toContain('<x-transport-status');
    }
});

it('keeps app.js free of hardcoded Russian text — every user-facing string must come from lang files', function () {
    // WHY-comments in app.js are (rightly) in Russian, but no *code* line
    // may carry a Cyrillic string literal: user-facing text belongs in
    // lang/*/errors.php, dispatched to the DOM only as translated markup.
    $codeLines = array_filter(
        file(resource_path('js/app.js')),
        fn (string $line) => ! str_starts_with(trim($line), '//'),
    );

    $code = implode('', $codeLines);

    expect($code)->not->toMatch('/[\x{0400}-\x{04FF}]/u');
});

/*
|--------------------------------------------------------------------------
| Род отказа, а не «что-то упало» (#83)
|--------------------------------------------------------------------------
*/

it('has its own wording for every kind of failure app.js can report', function () {
    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(__('errors.connection_lost_title'))
        ->assertSee(__('errors.server_error_title'))
        ->assertSee(__('errors.forbidden_title'))
        ->assertSee(__('errors.missing_title'))
        ->assertSee(__('errors.rejected_title'));
});

it('offers retry only for the failures a retry could actually fix', function () {
    // 403 повторяется в тот же 403 — «Повторить» там превращалось в
    // бесконечный цикл по одной и той же отклонённой команде.
    $markup = file_get_contents(resource_path('views/components/transport-status.blade.php'));

    // Повтор показывается по списку, а не по каждому виду отдельно: разъехаться
    // с app.js может только сам список.
    expect($markup)->toContain("'retry' => true")
        ->toContain('retryableKinds.includes(errorKind)')
        ->toContain('! retryableKinds.includes(errorKind)');

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(__('errors.retry'))
        ->assertSee(__('errors.page_reload'));
});

it('classifies every status app.js can see', function () {
    // Единственный ветвящийся по коду кусок JS — проверяем, что ни один
    // класс отказа не потерялся между app.js и разметкой.
    $js = file_get_contents(resource_path('js/app.js'));

    foreach (['server', 'forbidden', 'missing', 'rejected', 'network'] as $kind) {
        expect($js)->toContain("'{$kind}'");
    }
});
