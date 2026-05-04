<?php

use Livewire\Volt\Volt;

Volt::route('/', 'pages.booking')->name('booking');

Route::prefix('admin')->name('admin.')->group(function () {
    Volt::route('/appointments', 'pages.admin.appointments')->name('appointments');
    Volt::route('/specializations', 'pages.admin.specializations')->name('specializations');
    Volt::route('/barbers', 'pages.admin.barbers')->name('barbers');
    Volt::route('/clients', 'pages.admin.clients')->name('clients');
});
