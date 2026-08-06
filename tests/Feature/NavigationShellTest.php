<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Issue #68 — sidebar sections, wire:navigate, muted-text contrast
|--------------------------------------------------------------------------
*/

it('renders every sidebar section heading for an admin, with the expected items under each heading, in every locale', function (string $locale) {
    $admin = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $response = $this->actingAs($admin)
        ->withSession(['locale' => $locale])
        ->get(route('admin.dashboard'))
        ->assertOk();

    // The request already ran through SetLocale, so app()->getLocale() now matches
    // $locale — build the expected strings from the same lang files admin-header.blade.php
    // reads from, rather than duplicating hand-typed (and easy to mistype) translations.
    app()->setLocale($locale);

    $response->assertSeeInOrder([
        __('nav.section_operations'),
        __('nav.dashboard'), __('nav.appointments'), __('nav.earnings'), __('nav.booking'),
    ])->assertSeeInOrder([
        __('nav.section_business'),
        __('nav.barbers'), __('nav.services'), __('nav.specializations'), __('nav.clients'),
        __('nav.products'), __('nav.orders'), __('nav.debts'),
    ])->assertSeeInOrder([
        __('nav.section_communications'),
        __('nav.sms'), __('nav.sms_templates'), __('nav.sms_history'), __('nav.sms_settings'),
        __('nav.telegram'), __('nav.telegram_templates'), __('nav.telegram_broadcast'), __('nav.telegram_linked'),
    ])->assertSeeInOrder([
        // 'nav.users' only renders for a super admin — covered here since that's who we're
        // asserting as, and it lets this test exercise every item this heading can show.
        __('nav.section_system'),
        __('nav.settings'), __('nav.users'),
    ]);
})->with([
    'russian' => 'ru',
    'uzbek' => 'uz',
    'karakalpak' => 'kaa',
]);

it('gives every sidebar nav item and the logo wire:navigate instead of a full-page reload', function () {
    $admin = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $html = $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->getContent();

    $routes = [
        // booking doubles as the logo target for a non-barber and as its own "Booking" nav item.
        'admin.dashboard', 'admin.appointments', 'admin.earnings', 'booking',
        'admin.barbers', 'admin.services', 'admin.specializations', 'admin.clients',
        'admin.products', 'admin.orders', 'admin.debts',
        'admin.sms.templates', 'admin.sms.history', 'admin.sms.settings',
        'admin.telegram.templates', 'admin.telegram.broadcast', 'admin.telegram.linked',
        'admin.settings', 'admin.users',
    ];

    foreach ($routes as $routeName) {
        $href = 'href="'.route($routeName).'"';
        $total = substr_count($html, $href);
        $withNavigate = substr_count($html, $href.' wire:navigate');

        expect($total)->toBeGreaterThan(0, "Expected to find a link to [{$routeName}] in the admin chrome.");
        expect($withNavigate)->toBe(
            $total,
            "[{$routeName}]: only {$withNavigate}/{$total} occurrence(s) of href=\"...\" carried wire:navigate."
        );
    }
});

it('keeps the admin pages and shared components off the failing muted text-content opacity classes', function () {
    // These two public-facing pages are outside issue #68's scope — the issue's own "Код:"
    // pointer list names only admin-header.blade.php and layouts/app.blade.php, and this
    // change deliberately left the booking flow and the login screen untouched.
    $deliberatelySkipped = [
        'livewire/pages/booking.blade.php',
        'livewire/pages/auth/login.blade.php',
    ];

    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relativePath = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relativePath, $deliberatelySkipped, true)) {
            continue;
        }

        foreach (file($file->getPathname()) as $lineNumber => $line) {
            // (?!\d) keeps this from ever matching a future, still-passing opacity value
            // like text-content/450 — only the exact 45/30/20 tokens the issue flagged.
            if (preg_match('#text-content/(?:45|30|20)(?!\d)#', $line)) {
                $offenders[] = sprintf('%s:%d: %s', $relativePath, $lineNumber + 1, trim($line));
            }
        }
    }

    $message = $offenders === []
        ? ''
        : "Found the failing muted classes (text-content/45|30|20) — replace with text-content-muted/-subtle:\n".implode("\n", $offenders);

    expect($offenders)->toBe([], $message);
});
