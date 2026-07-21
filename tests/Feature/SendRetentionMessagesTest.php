<?php

use App\Models\Client;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('does not send or stamp anything on a dry run', function () {
    $client = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(21),
        'last_retention_sent_at' => null,
    ]);

    $this->mock(SmsService::class)
        ->shouldNotReceive('sendSms');

    $this->artisan('app:send-retention-messages', ['--dry-run' => true])
        ->assertSuccessful();

    expect($client->fresh()->last_retention_sent_at)->toBeNull();
});

it('sends a single test SMS to the given number without touching clients', function () {
    $client = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(21),
        'last_retention_sent_at' => null,
    ]);

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')
        ->once()
        ->with('998901112233', NotificationTemplates::renderSms('retention'), null, 'retention')
        ->andReturnTrue();

    $this->artisan('app:send-retention-messages', ['--phone' => '998 90 111 22 33'])
        ->assertSuccessful();

    // The eligible client must NOT be stamped by a test send.
    expect($client->fresh()->last_retention_sent_at)->toBeNull();
});

it('rejects an invalid test number', function () {
    $this->mock(SmsService::class)
        ->shouldNotReceive('sendSms');

    $this->artisan('app:send-retention-messages', ['--phone' => '12345'])
        ->assertFailed();
});

it('sends and stamps eligible clients on a real run', function () {
    $client = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(21),
        'last_retention_sent_at' => null,
    ]);

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')
        ->once()
        ->with($client->phone, NotificationTemplates::renderSms('retention'), $client->id, 'retention')
        ->andReturnTrue();

    $this->artisan('app:send-retention-messages')->assertSuccessful();

    expect($client->fresh()->last_retention_sent_at)->not->toBeNull();
});
