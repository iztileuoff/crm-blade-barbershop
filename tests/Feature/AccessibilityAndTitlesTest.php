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
