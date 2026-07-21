<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEventForSignature(string $signature): ?Event
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        if (str_contains($event->command ?? '', $signature)) {
            return $event;
        }
    }

    return null;
}

it('schedules the retention SMS command daily at 10:00', function () {
    $event = scheduledEventForSignature('app:send-retention-messages');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 10 * * *');
});

it('keeps the upcoming-reminder command scheduled every minute', function () {
    $event = scheduledEventForSignature('app:send-upcoming-reminders');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
