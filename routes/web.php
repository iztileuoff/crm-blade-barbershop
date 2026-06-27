<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\RestrictBarberAccess;
use Livewire\Volt\Volt;

// Public online booking — no auth required. Logged-in admins still see the
// admin chrome via the booking layout's @auth branch.
Volt::route('/', 'pages.booking')->name('booking');

// Telegram-бот (webhook). Безопасность — secret_token (safe_mode) на стороне Nutgram.
// Контроллер (не замыкание), чтобы работал route:cache на проде.
Route::post('/telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

// Switch the UI language. Stored in the session and applied by the SetLocale middleware.
Route::get('/locale/{locale}', function (string $locale) {
    if (array_key_exists($locale, config('app.supported_locales', []))) {
        session(['locale' => $locale]);
    }

    return redirect()->back();
})->name('locale.switch');

Volt::route('/login', 'pages.auth.login')->name('login')->middleware('guest');
Route::get('/logout', function () {
    auth()->logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::prefix('admin')->name('admin.')->middleware(['auth', RestrictBarberAccess::class])->group(function () {
    Volt::route('/', 'pages.admin.dashboard')->name('dashboard');
    Volt::route('/appointments', 'pages.admin.appointments')->name('appointments');
    Volt::route('/specializations', 'pages.admin.specializations')->name('specializations');
    Volt::route('/barbers', 'pages.admin.barbers')->name('barbers');
    Volt::route('/services', 'pages.admin.services')->name('services');
    Volt::route('/clients', 'pages.admin.clients')->name('clients');
    Volt::route('/clients/{client}', 'pages.admin.clients.show')->name('clients.show');
    Volt::route('/products', 'pages.admin.products')->name('products');
    Volt::route('/orders', 'pages.admin.orders')->name('orders');
    Volt::route('/debts', 'pages.admin.debts')->name('debts');

    Volt::route('/sms/templates', 'pages.admin.sms.templates')->name('sms.templates');
    Volt::route('/sms/history', 'pages.admin.sms.history')->name('sms.history');
    Volt::route('/sms/settings', 'pages.admin.sms.settings')->name('sms.settings');

    Volt::route('/telegram/templates', 'pages.admin.telegram.templates')->name('telegram.templates');
    Volt::route('/telegram/broadcast', 'pages.admin.telegram.broadcast')->name('telegram.broadcast');
    Volt::route('/telegram/linked', 'pages.admin.telegram.linked')->name('telegram.linked');

    Volt::route('/settings', 'pages.admin.settings')->name('settings');
    Volt::route('/users', 'pages.admin.users')->name('users');
});
