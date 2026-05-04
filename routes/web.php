<?php

use Livewire\Volt\Volt;

Volt::route('/', 'pages.booking')->name('booking');
Volt::route('/admin/appointments', 'pages.admin.appointments')->name('admin.appointments');
