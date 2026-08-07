<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The 17 admin.* pages — same list PagesRenderTest and NavigationShellTest
 * already exercise, minus the public `booking` route, which lives under its
 * own layout/title namespace and isn't an "admin page".
 *
 * @return array<int, string>
 */
function a11yAdminRoutes(): array
{
    return [
        'admin.dashboard', 'admin.appointments', 'admin.specializations', 'admin.barbers',
        'admin.services', 'admin.clients', 'admin.products', 'admin.orders', 'admin.debts',
        'admin.sms.templates', 'admin.sms.history', 'admin.sms.settings',
        'admin.telegram.templates', 'admin.telegram.broadcast', 'admin.telegram.linked',
        'admin.settings', 'admin.users',
    ];
}

function extractTitleTag(string $html, string $context): string
{
    preg_match('#<title>(.*?)</title>#s', $html, $matches);

    expect($matches)->not->toBeEmpty("No <title> tag found for [{$context}].");

    return trim($matches[1]);
}

/**
 * Expected <title> text per admin route, built from the exact same lang keys
 * (and, for the six SMS/Telegram pages, the exact same ' — Blade Barbershop'
 * suffix) the pages themselves use — same convention NavigationShellTest uses
 * for nav labels, so a lang-file typo shows up as a real failure instead of
 * being baked into a hand-typed expectation.
 *
 * @return array<string, string>
 */
function a11yExpectedAdminTitles(string $locale): array
{
    app()->setLocale($locale);

    return [
        'admin.dashboard' => __('dashboard.page_title'),
        'admin.appointments' => __('appointments.page_title'),
        'admin.specializations' => __('specializations.page_title'),
        'admin.barbers' => __('barbers.page_title'),
        'admin.services' => __('services.page_title'),
        'admin.clients' => __('clients.page_title'),
        'admin.products' => __('products.page_title'),
        'admin.orders' => __('orders.page_title'),
        'admin.debts' => __('debts.page_title'),
        'admin.settings' => __('settings.page_title'),
        'admin.users' => __('users.page_title'),
        'admin.sms.templates' => __('sms.templates_title').' — Blade Barbershop',
        'admin.sms.history' => __('sms.history_title').' — Blade Barbershop',
        'admin.sms.settings' => __('sms.settings_title').' — Blade Barbershop',
        'admin.telegram.templates' => __('telegram.templates_title').' — Blade Barbershop',
        'admin.telegram.broadcast' => __('telegram.broadcast_title').' — Blade Barbershop',
        'admin.telegram.linked' => __('telegram.linked_title').' — Blade Barbershop',
    ];
}

/**
 * One [route, locale] pair per dataset row, so every case gets its own fresh
 * app/request. Looping routes with several ->get() calls inside a single test
 * instead would share one booted app across "requests" the way real, separate
 * page visits never do — and Livewire's full-page <x-slot:title> forwarding
 * (SupportPageComponents::renderContentsIntoLayout()) leaks the previous
 * page's slot into the next request's title on exactly that shared-app setup.
 * Confirmed with the app unmodified: real, isolated requests never mix titles.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function a11yTitleDataset(): array
{
    $cases = [];

    foreach (['russian' => 'ru', 'uzbek' => 'uz', 'karakalpak' => 'kaa'] as $localeLabel => $locale) {
        foreach (a11yAdminRoutes() as $routeName) {
            $cases["{$localeLabel}: {$routeName}"] = [$routeName, $locale];
        }
    }

    return $cases;
}

/*
|--------------------------------------------------------------------------
| Issue #77 — every admin page gets its own <title>, in every locale
|--------------------------------------------------------------------------
*/

it('renders the expected <title> for an admin page', function (string $routeName, string $locale) {
    $user = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    $expected = a11yExpectedAdminTitles($locale)[$routeName];

    $html = $this->actingAs($user)
        ->withSession(['locale' => $locale])
        ->get(route($routeName))
        ->assertOk()
        ->getContent();

    expect(extractTitleTag($html, "{$routeName} ({$locale})"))->toBe($expected);
})->with(a11yTitleDataset());

it('never gives two admin pages the same <title>', function (string $locale) {
    $titles = a11yExpectedAdminTitles($locale);

    $duplicates = array_filter(array_count_values($titles), fn (int $count) => $count > 1);

    expect($duplicates)->toBe(
        [],
        "Admin pages share a <title> in [{$locale}]: ".json_encode($titles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
})->with([
    'russian' => 'ru',
    'uzbek' => 'uz',
    'karakalpak' => 'kaa',
]);

/*
|--------------------------------------------------------------------------
| Issue #77 — icon-only buttons must carry an accessible name
|--------------------------------------------------------------------------
*/

/**
 * Every icon-only <button> (svg child, no visible text) on the page that
 * lacks an aria-label or a title attribute — the two accessible-name escape
 * hatches this codebase's convention relies on (see e.g. common.edit/common.delete).
 *
 * @return array<int, string>
 */
function iconOnlyButtonOffenders(string $html, string $page): array
{
    $previousSetting = libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previousSetting);

    $offenders = [];

    foreach ($dom->getElementsByTagName('button') as $button) {
        $hasAccessibleAttr = trim($button->getAttribute('aria-label')) !== ''
            || trim($button->getAttribute('title')) !== '';

        if ($hasAccessibleAttr) {
            continue;
        }

        if ($button->getElementsByTagName('svg')->length === 0) {
            // Not icon-only — either plain text or has no icon at all.
            continue;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $button->textContent) ?? '');

        if ($text !== '') {
            // Icon + visible text (or sr-only text) already has an accessible name.
            continue;
        }

        $snippet = trim(preg_replace('/\s+/u', ' ', $dom->saveHTML($button)) ?? '');
        $offenders[] = sprintf('[%s] %s', $page, mb_substr($snippet, 0, 200));
    }

    return $offenders;
}

it('gives every icon-only button on the main admin pages an accessible name', function () {
    $admin = User::factory()->create(['role' => Role::SUPER_ADMIN]);

    // One real row per page so table-row action buttons (edit/delete/stock
    // steppers — the "карандаш/корзина/крестики" the issue names) actually
    // render instead of an empty state.
    $barber = Barber::factory()->create();
    Service::factory()->create();
    $client = Client::factory()->create();
    Product::create(['name' => 'Тестовый товар', 'selling_price' => 10000, 'stock' => 5, 'is_active' => true]);
    Order::create(['client_id' => $client->id, 'total_price' => 40000, 'debt_amount' => 10000, 'payment_type' => 'cash']);
    User::factory()->create(['role' => Role::ADMIN]);

    Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'status' => AppointmentStatus::Pending,
        'starts_at' => Carbon::parse('2026-08-10 10:00:00', 'Asia/Tashkent'),
        'ends_at' => Carbon::parse('2026-08-10 11:00:00', 'Asia/Tashkent'),
        'price' => 30000,
        'debt_amount' => 10000,
    ]);

    $routes = [...a11yAdminRoutes(), 'booking'];

    $offenders = [];

    foreach ($routes as $routeName) {
        $params = $routeName === 'admin.appointments' ? ['date' => '2026-08-10'] : [];

        $html = $this->actingAs($admin)
            ->get(route($routeName, $params))
            ->assertOk()
            ->getContent();

        $offenders = [...$offenders, ...iconOnlyButtonOffenders($html, $routeName)];
    }

    expect($offenders)->toBe([], "Icon-only <button> without aria-label/title:\n".implode("\n", $offenders));
});

/*
|--------------------------------------------------------------------------
| Полный ARIA-паттерн табов (#77)
|--------------------------------------------------------------------------
*/

/**
 * Разметка набора вкладок на странице: сам tablist и обе/все его вкладки.
 */
function tabMarkupFor(string $html): string
{
    preg_match('#<div[^>]*role="tablist".*?</div>#s', $html, $matches);

    expect($matches)->not->toBeEmpty('No role="tablist" found on the page.');

    return $matches[0];
}

it('gives the dashboard tabs a named tablist, roving tabindex and arrow keys', function () {
    $admin = User::factory()->create(['role' => Role::ADMIN]);

    $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

    $tabs = tabMarkupFor($html);

    expect($tabs)
        ->toContain('aria-label="'.__('dashboard.tabs_label').'"')
        // Roving tabindex: Tab заводит в набор один раз, дальше — стрелки.
        ->toContain('tabindex="0"')
        ->toContain('tabindex="-1"')
        ->toContain('aria-selected="true"')
        ->toContain('aria-selected="false"')
        ->toContain('x-on:keydown.right.prevent')
        ->toContain('x-on:keydown.left.prevent')
        ->toContain('x-on:keydown.home.prevent')
        ->toContain('x-on:keydown.end.prevent');
});

it('points the active dashboard tab at a panel that actually exists', function () {
    $admin = User::factory()->create(['role' => Role::ADMIN]);

    $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)
        ->toContain('id="dashboard-day-tab"')
        ->toContain('aria-controls="dashboard-day"')
        ->toContain('id="dashboard-day" role="tabpanel" aria-labelledby="dashboard-day-tab"')
        // Неактивная вкладка ни на что не ссылается: панель в разметке одна.
        ->not->toContain('aria-controls="dashboard-month"');
});

it('gives the client card tabs the same treatment', function () {
    $admin = User::factory()->create(['role' => Role::ADMIN]);
    $client = Client::factory()->create();

    $html = $this->actingAs($admin)->get(route('admin.clients.show', $client))->assertOk()->getContent();

    expect(tabMarkupFor($html))
        ->toContain('aria-label="'.__('clients.tabs_label').'"')
        ->toContain('tabindex="0"')
        ->toContain('tabindex="-1"');

    expect($html)
        ->toContain('id="client-history-appointments-tab"')
        ->toContain('aria-controls="client-history-appointments"')
        ->toContain('id="client-history-appointments" role="tabpanel" aria-labelledby="client-history-appointments-tab"')
        ->not->toContain('aria-controls="client-history-sms"');
});

/*
|--------------------------------------------------------------------------
| Столбцы графика читаются пальцем (#77)
|--------------------------------------------------------------------------
*/

it('lets a tablet read the exact figures for a day off the chart', function () {
    // <title> внутри <rect> открывается только по hover, а планшет, на котором
    // и живёт эта страница, hover'а не знает.
    $html = Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->html();

    expect($html)
        ->toContain(__('dashboard.chart_tap_hint'))
        ->toContain('x-on:click="pick(0)"')
        // Зона попадания — вся колонка: палец не целится в четыре пикселя.
        ->toContain(':fill-opacity="picked === 0 ? 0.07 : 0"');
});

it('lets the keyboard walk the chart without adding a tab stop per day', function () {
    $html = Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.dashboard')
        ->set('activeTab', 'month')
        ->html();

    expect($html)
        ->toContain('aria-label="'.__('dashboard.chart_keyboard_label').'"')
        ->toContain('aria-live="polite"')
        ->toContain('x-on:keydown.escape="picked = null"');

    // Зона попадания на каждый день месяца — но таб-стоп на график один.
    expect(substr_count($html, 'x-on:click="pick('))->toBeGreaterThan(20)
        ->and(substr_count($html, 'aria-label="'.__('dashboard.chart_keyboard_label').'"'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Мелочи формы продаж и i18n (#77)
|--------------------------------------------------------------------------
*/

it('debounces the split-payment amounts and shows progress while an order is deleted', function () {
    // Поля нал/карта появляются только при раздельной оплате, и только в
    // открытой модалке.
    Order::create(['total_price' => 50000, 'payment_type' => 'cash']);

    $html = Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.orders')
        ->call('openCreate')
        ->set('payment_type', 'both')
        ->html();

    expect($html)
        ->toContain('wire:model.number.live.debounce.500ms="cash_amount"')
        ->toContain('wire:model.number.live.debounce.500ms="card_amount"')
        ->toContain('wire:loading.attr="disabled" wire:target="deleteOrder(');
});

it('translates the admin panel label in every locale', function () {
    foreach (['ru', 'uz', 'kaa'] as $locale) {
        app()->setLocale($locale);

        expect(__('nav.admin_panel'))->not->toBe('Admin Panel');
    }
});
