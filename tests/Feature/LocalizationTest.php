<?php

use App\Enums\AppointmentStatus;
use App\Enums\PaymentType;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Counts whole-word occurrences of a currency word in rendered HTML, so a
 * substring collision (e.g. "sum" inside unrelated markup) can't produce a
 * false positive.
 */
function currencyWordCount(string $html, string $word): int
{
    return preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $html);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => Role::SUPER_ADMIN]));
});

it('defaults the booking page to Russian', function () {
    $this->get(route('booking'))
        ->assertOk()
        ->assertSee('Выберите услугу')
        ->assertSee('<html lang="ru"', escape: false);
});

it('stores a supported locale in the session and redirects back', function () {
    $this->from(route('booking'))
        ->get(route('locale.switch', 'uz'))
        ->assertRedirect(route('booking'));

    expect(session('locale'))->toBe('uz');
});

it('ignores an unsupported locale', function () {
    $this->withSession(['locale' => 'ru'])
        ->get(route('locale.switch', 'de'));

    expect(session('locale'))->toBe('ru');
});

it('renders the booking page in the selected locale', function (string $locale, string $expected, string $htmlLang) {
    $this->withSession(['locale' => $locale])
        ->get(route('booking'))
        ->assertOk()
        ->assertSee($expected)
        ->assertSee('<html lang="'.$htmlLang.'"', escape: false);
})->with([
    'russian' => ['ru', 'Выберите услугу', 'ru'],
    'uzbek' => ['uz', 'Xizmatni tanlang', 'uz'],
    'karakalpak' => ['kaa', 'Xızmetti saylań', 'kaa'],
]);

it('translates booking validation messages for the active locale', function () {
    app()->setLocale('uz');

    expect(__('booking.validation.invalid_phone'))->toBe('Toʻgʻri raqam kiriting: 998XXXXXXXXX');

    app()->setLocale('kaa');

    expect(__('booking.validation.invalid_phone'))->toBe('Durıs nomer kiritiń: 998XXXXXXXXX');
});

it('renders the admin dashboard in the selected locale', function (string $locale, string $expected) {
    $this->withSession(['locale' => $locale])
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee($expected);
})->with([
    'russian' => ['ru', 'Сводка за день'],
    'uzbek' => ['uz', 'Kunlik hisobot'],
    'karakalpak' => ['kaa', 'Kúnlik esabat'],
]);

it('translates enum labels for the active locale', function () {
    app()->setLocale('uz');
    expect(AppointmentStatus::Completed->label())->toBe('Yakunlangan');
    expect(PaymentType::Cash->label())->toBe('Naqd');

    app()->setLocale('kaa');
    expect(AppointmentStatus::Completed->label())->toBe('Juwmaqlanǵan');
    expect(Role::BARBER->label())->toBe('Barber');
});

it('formats a date with month names in the active locale', function (string $locale, string $expected) {
    app()->setLocale($locale);

    expect(Client::formatLocalizedDate(Carbon\Carbon::create(2026, 6, 16)))->toBe($expected);
})->with([
    'russian' => ['ru', '16 июня 2026'],
    'uzbek' => ['uz', '16 iyun 2026'],
    'karakalpak' => ['kaa', '16 iyun 2026'],
]);

it('formats model money accessors with the active locale\'s currency word, not a hardcoded one', function (string $locale, string $currency) {
    app()->setLocale($locale);

    $barber = Barber::factory()->create(['price' => 25000]);
    $product = Product::create(['name' => 'Wax', 'selling_price' => 15000]);
    $order = Order::create(['total_price' => 40000, 'debt_amount' => 10000]);
    $appointment = Appointment::factory()->create(['price' => 30000, 'debt_amount' => 5000]);

    expect($barber->formattedPrice)->toBe('25 000 '.$currency)
        ->and($product->formattedPrice)->toBe('15 000 '.$currency)
        ->and($order->formattedTotal)->toBe('40 000 '.$currency)
        ->and($order->formattedDebt)->toBe('10 000 '.$currency)
        ->and($appointment->formattedPrice)->toBe('30 000 '.$currency)
        ->and($appointment->formattedDebt)->toBe('5 000 '.$currency);
})->with([
    'russian' => ['ru', 'сум'],
    'uzbek' => ['uz', 'soʻm'],
    'karakalpak' => ['kaa', 'sum'],
]);

it('keeps a single currency word on a client page mixing accessor-formatted and template-formatted money', function (string $locale, string $currency, array $otherCurrencies) {
    app()->setLocale($locale);

    $client = Client::factory()->create();
    Appointment::factory()->create([
        'client_id' => $client->id,
        'status' => AppointmentStatus::Completed,
        'price' => 20000,
        'debt_amount' => 0,
    ]);
    Order::create(['client_id' => $client->id, 'total_price' => 40000, 'debt_amount' => 10000]);

    // The metrics block is template-formatted via the component's own money()
    // helper; the orders tab is model-accessor-formatted via
    // Order::formattedTotal/formattedDebt. Both must agree on one currency word.
    $html = Livewire::test('pages.admin.clients.show', ['client' => $client])
        ->call('showTab', 'orders')
        ->assertSeeText('60 000 '.$currency) // metrics: totalSpent (template)
        ->assertSeeText('10 000 '.$currency) // metrics: totalDebt (template)
        ->assertSeeText('40 000 '.$currency) // orders tab: formattedTotal (accessor)
        ->html();

    expect(currencyWordCount($html, $currency))->toBeGreaterThan(0);

    foreach ($otherCurrencies as $otherCurrency) {
        expect(currencyWordCount($html, $otherCurrency))
            ->toBe(0, "Found the wrong-locale currency word \"{$otherCurrency}\" mixed in with \"{$currency}\".");
    }
})->with([
    'russian' => ['ru', 'сум', ['soʻm', 'sum']],
    'uzbek' => ['uz', 'soʻm', ['сум', 'sum']],
    'karakalpak' => ['kaa', 'sum', ['сум', 'soʻm']],
]);
