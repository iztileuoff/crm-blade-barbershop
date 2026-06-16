<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
