<?php

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * These views must render without a live session or database — that's the
 * whole point of the issue (#75): a tablet hits 419/500/503 precisely when
 * one of those is unavailable. Routes are registered ad-hoc per test because
 * Laravel's CSRF middleware skips verification entirely while running tests
 * (Middleware::runningUnitTests()), so the only reliable way to reach a real
 * 419/500/503 response is to throw the exception Laravel itself would.
 */
it('returns a localized 419 page for a token mismatch in every supported locale, without an authenticated session', function (string $locale, string $title, string $body) {
    Route::get('/__test/419', fn () => throw new TokenMismatchException)->middleware('web');

    $this->withSession(['locale' => $locale])
        ->get('/__test/419')
        ->assertStatus(419)
        ->assertSee($title)
        ->assertSee($body)
        ->assertSee(route('login'), escape: false);

    $this->assertGuest();
})->with([
    'russian' => ['ru', 'Сессия истекла', 'Время сессии вышло. Обновите страницу и войдите заново.'],
    'uzbek' => ['uz', 'Sessiya tugadi', 'Sessiya vaqti tugadi. Sahifani yangilab, qayta kiring.'],
    'karakalpak' => ['kaa', 'Sessiya tamamlandı', 'Sessiya waqtı tamamlandı. Bettі jańalap, qayta kiriń.'],
]);

it('returns a localized 500 page for an http exception in every supported locale, without an authenticated session', function (string $locale, string $title, string $body) {
    Route::get('/__test/500', fn () => throw new HttpException(500))->middleware('web');

    $this->withSession(['locale' => $locale])
        ->get('/__test/500')
        ->assertStatus(500)
        ->assertSee($title)
        ->assertSee($body);

    $this->assertGuest();
})->with([
    'russian' => ['ru', 'Что-то пошло не так', 'На сервере произошла ошибка. Попробуйте обновить страницу через минуту.'],
    'uzbek' => ['uz', "Nimadir noto'g'ri ketdi", "Serverda xatolik yuz berdi. Bir daqiqadan so'ng sahifani yangilab ko'ring."],
    'karakalpak' => ['kaa', 'Bir nárse qátelesti', 'Serverde qátelik júz berdi. Bir minuttan soń bettі jańalap kóriń.'],
]);

it('returns a localized 503 page for an http exception in every supported locale, without an authenticated session', function (string $locale, string $title, string $body) {
    Route::get('/__test/503', fn () => throw new HttpException(503))->middleware('web');

    $this->withSession(['locale' => $locale])
        ->get('/__test/503')
        ->assertStatus(503)
        ->assertSee($title)
        ->assertSee($body);

    $this->assertGuest();
})->with([
    'russian' => ['ru', 'Технические работы', 'Сервис временно недоступен. Мы уже знаем о проблеме — попробуйте зайти чуть позже.'],
    'uzbek' => ['uz', 'Texnik ishlar', "Xizmat vaqtincha mavjud emas. Biz allaqachon bilamiz — birozdan so'ng qayta kiring."],
    'karakalpak' => ['kaa', 'Texnikalıq jumıslar', 'Xızmet waqtınsha islemeydi. Biz bul haqqında bilemiz — biraz waqıttan soń qayta kiriń.'],
]);

it("returns the localized 419 page instead of Laravel's stock English one for a request with a genuinely invalid CSRF token", function () {
    // `php artisan test` runs inside the console, and PreventRequestForgery
    // bypasses verification whenever `app()->runningInConsole() &&
    // app()->runningUnitTests()` — both true for every test in this suite.
    // That's the exact class bootstrap/app.php wires into the `web` group
    // via `$middleware->validateCsrfTokens()`. We defeat only the "are we
    // in tests" shortcut so a genuinely mismatched token takes the real
    // isReading/hasValidOrigin/tokensMatch code path — the one a tablet
    // with a stale page actually hits in production — rather than
    // synthesizing the exception by hand.
    //
    // Route::middleware() casts every entry to a string before it's stored
    // (Route::middleware() in the framework), so a Closure/object can't be
    // passed directly — it's bound into the container under a throwaway key
    // instead and referenced by that key, exactly like any string alias.
    $forcedCsrf = new class(app(), app(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };
    app()->instance('test.forced-csrf', $forcedCsrf);

    Route::post('/__test/csrf', fn () => 'ok')->middleware(['web', 'test.forced-csrf']);

    $this->post('/__test/csrf', ['_token' => 'not-the-real-token'])
        ->assertStatus(419)
        ->assertSee(__('errors.page_419_title'))
        ->assertSee(__('errors.page_419_body'))
        ->assertDontSee('Page Expired');

    $this->assertGuest();
});

it('falls back to the localized 500 page for an unexpected exception in production mode', function () {
    // In debug mode (the test default) Laravel shows the Whoops trace for a
    // generic, non-HttpException — by design, for developers. Production
    // runs with debug off, which is the actual scenario the issue is about:
    // an unhandled failure (e.g. a lost database connection) must still show
    // our styled page, not a stack trace.
    config(['app.debug' => false]);

    Route::get('/__test/500-generic', fn () => throw new RuntimeException('boom'))->middleware('web');

    $this->get('/__test/500-generic')
        ->assertStatus(500)
        ->assertSee(__('errors.page_500_title'))
        ->assertDontSee('boom');
});

it('renders every error page in every supported locale without touching session or auth', function (string $locale, string $title419, string $title500, string $title503) {
    app()->setLocale($locale);

    // e() — Blade's {{ }} HTML-escapes output, so an apostrophe in a Uzbek
    // title renders as `&#039;`, not a literal `'`.
    expect(view('errors.419', ['exception' => new HttpException(419)])->render())->toContain(e($title419));
    expect(view('errors.500', ['exception' => new HttpException(500)])->render())->toContain(e($title500));
    expect(view('errors.503', ['exception' => new HttpException(503)])->render())->toContain(e($title503));
})->with([
    'russian' => ['ru', 'Сессия истекла', 'Что-то пошло не так', 'Технические работы'],
    'uzbek' => ['uz', 'Sessiya tugadi', 'Nimadir noto\'g\'ri ketdi', 'Texnik ishlar'],
    'karakalpak' => ['kaa', 'Sessiya tamamlandı', 'Bir nárse qátelesti', 'Texnikalıq jumıslar'],
]);

it('keeps the error page templates free of session, auth and database calls', function () {
    // Static guard: these views must survive rendering even when the
    // request never made it past a broken session/DB, so nothing in them
    // may depend on either being available.
    $forbidden = ['session(', 'Session::', 'auth(', 'Auth::', '::query(', '::find(', 'DB::'];

    foreach (['419', '500', '503'] as $code) {
        $source = file_get_contents(resource_path("views/errors/{$code}.blade.php"));

        foreach ($forbidden as $needle) {
            expect($source)->not->toContain($needle, "errors/{$code}.blade.php unexpectedly calls {$needle}");
        }
    }

    $shellSource = file_get_contents(resource_path('views/components/error-shell.blade.php'));

    foreach ($forbidden as $needle) {
        expect($shellSource)->not->toContain($needle, "components/error-shell.blade.php unexpectedly calls {$needle}");
    }
});
