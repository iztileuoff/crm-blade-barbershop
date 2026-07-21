<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('stamps client last_visit_at when an appointment is marked completed', function () {
    $visit = Carbon::now()->subDays(3)->setTime(14, 0);
    $appointment = Appointment::factory()->create([
        'status' => AppointmentStatus::Pending,
        'starts_at' => $visit,
    ]);

    expect($appointment->client->fresh()->last_visit_at)->toBeNull();

    $appointment->update(['status' => AppointmentStatus::Completed]);

    expect($appointment->client->fresh()->last_visit_at->toDateTimeString())
        ->toBe($visit->toDateTimeString());
});

it('does not move last_visit_at backwards for an older completed appointment', function () {
    $client = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(2)->setTime(12, 0),
    ]);

    $older = Appointment::factory()->for($client)->create([
        'status' => AppointmentStatus::Pending,
        'starts_at' => Carbon::now()->subDays(30)->setTime(10, 0),
    ]);

    $older->update(['status' => AppointmentStatus::Completed]);

    expect($client->fresh()->last_visit_at->toDateString())
        ->toBe(Carbon::now()->subDays(2)->toDateString());
});

it('backfills last_visit_at from the latest completed appointment', function () {
    $client = Client::factory()->create(['last_visit_at' => null]);

    // Two completed visits; the observer sets last_visit_at as they are created.
    Appointment::factory()->for($client)->create([
        'status' => AppointmentStatus::Completed,
        'starts_at' => Carbon::now()->subDays(40)->setTime(11, 0),
    ]);
    $latest = Carbon::now()->subDays(21)->setTime(16, 0);
    Appointment::factory()->for($client)->create([
        'status' => AppointmentStatus::Completed,
        'starts_at' => $latest,
    ]);

    // Simulate legacy data: the field was never populated.
    $client->forceFill(['last_visit_at' => null])->save();

    $migration = require database_path('migrations/2026_07_21_123904_backfill_clients_last_visit_at.php');
    $migration->up();

    expect($client->fresh()->last_visit_at->toDateTimeString())
        ->toBe($latest->toDateTimeString());
});

it('leaves last_visit_at null for a client with no completed visits', function () {
    $client = Client::factory()->create(['last_visit_at' => null]);
    Appointment::factory()->for($client)->create([
        'status' => AppointmentStatus::Cancelled,
        'starts_at' => Carbon::now()->subDays(10),
    ]);
    $client->forceFill(['last_visit_at' => null])->save();

    $migration = require database_path('migrations/2026_07_21_123904_backfill_clients_last_visit_at.php');
    $migration->up();

    expect($client->fresh()->last_visit_at)->toBeNull();
});
