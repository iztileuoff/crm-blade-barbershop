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
