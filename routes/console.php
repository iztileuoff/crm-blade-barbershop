<?php

use App\Console\Commands\SendRetentionMessages;
use App\Console\Commands\SendUpcomingReminders;
use App\Console\Commands\SyncSmsStatuses;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(SendUpcomingReminders::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(SyncSmsStatuses::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(SendRetentionMessages::class)
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->onOneServer();
