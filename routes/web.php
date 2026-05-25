<?php

use App\Http\Middleware\RestrictBarberAccess;
use Livewire\Volt\Volt;

Volt::route('/', 'pages.booking')->name('booking')->middleware('auth');

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
    Volt::route('/products', 'pages.admin.products')->name('products');
    Volt::route('/orders', 'pages.admin.orders')->name('orders');
    Volt::route('/debts', 'pages.admin.debts')->name('debts');
    Volt::route('/settings', 'pages.admin.settings')->name('settings');
    Volt::route('/users', 'pages.admin.users')->name('users');
});
