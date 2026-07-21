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

it('targets clients absent the retention window OR longer, but not more recent ones', function () {
    config(['services.barbershop.retention_days' => 14]);

    $exactly = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(14)->setTime(12, 0),
        'last_retention_sent_at' => null,
    ]);
    $older = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(40)->setTime(12, 0),
        'last_retention_sent_at' => null,
    ]);
    $tooRecent = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(13)->setTime(12, 0),
        'last_retention_sent_at' => null,
    ]);
    $neverVisited = Client::factory()->create(['last_visit_at' => null]);

    $sms = $this->mock(SmsService::class);
    $sms->shouldReceive('sendSms')->twice()->andReturnTrue();

    $this->artisan('app:send-retention-messages')->assertSuccessful();

    expect($exactly->fresh()->last_retention_sent_at)->not->toBeNull()
        ->and($older->fresh()->last_retention_sent_at)->not->toBeNull()
        ->and($tooRecent->fresh()->last_retention_sent_at)->toBeNull()
        ->and($neverVisited->fresh()->last_retention_sent_at)->toBeNull();
});

it('does not re-nudge a client messaged within the retention window', function () {
    config(['services.barbershop.retention_days' => 14]);

    $recentlyMessaged = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(40),
        'last_retention_sent_at' => Carbon::now()->subDays(5),
    ]);
    $dueAgain = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(40),
        'last_retention_sent_at' => Carbon::now()->subDays(20),
    ]);

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')
        ->once()
        ->with($dueAgain->phone, NotificationTemplates::renderSms('retention'), $dueAgain->id, 'retention')
        ->andReturnTrue();

    $this->artisan('app:send-retention-messages')->assertSuccessful();

    expect($dueAgain->fresh()->last_retention_sent_at->isToday())->toBeTrue()
        ->and($recentlyMessaged->fresh()->last_retention_sent_at->toDateString())
        ->toBe(Carbon::now()->subDays(5)->toDateString());
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
