<?php

use App\Models\Client;
use App\Models\Setting;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('does not send or stamp anything on a dry run', function () {
    config(['services.barbershop.retention_days' => 14]);
    $client = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(14),
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

it('by default targets only the exact N-day cohort, not the older backlog', function () {
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

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')
        ->once()
        ->with($exactly->phone, NotificationTemplates::renderSms('retention'), $exactly->id, 'retention')
        ->andReturnTrue();

    $this->artisan('app:send-retention-messages')->assertSuccessful();

    expect($exactly->fresh()->last_retention_sent_at)->not->toBeNull()
        ->and($older->fresh()->last_retention_sent_at)->toBeNull()
        ->and($tooRecent->fresh()->last_retention_sent_at)->toBeNull();
});

it('with --backlog targets everyone absent N or more days', function () {
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

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')->twice()->andReturnTrue();

    $this->artisan('app:send-retention-messages', ['--backlog' => true])->assertSuccessful();

    expect($exactly->fresh()->last_retention_sent_at)->not->toBeNull()
        ->and($older->fresh()->last_retention_sent_at)->not->toBeNull()
        ->and($tooRecent->fresh()->last_retention_sent_at)->toBeNull()
        ->and($neverVisited->fresh()->last_retention_sent_at)->toBeNull();
});

it('sends the retention text in each client’s own stored locale', function () {
    config(['services.barbershop.retention_days' => 14]);
    Setting::set('sms_locale', 'ru');

    $client = Client::factory()->create([
        'last_visit_at' => Carbon::now()->subDays(14),
        'last_retention_sent_at' => null,
        'locale' => 'kaa',
    ]);

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')
        ->once()
        ->with($client->phone, NotificationTemplates::renderSms('retention', [], 'kaa'), $client->id, 'retention')
        ->andReturnTrue();

    $this->artisan('app:send-retention-messages')->assertSuccessful();
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

    $this->artisan('app:send-retention-messages', ['--backlog' => true])->assertSuccessful();

    expect($dueAgain->fresh()->last_retention_sent_at->isToday())->toBeTrue()
        ->and($recentlyMessaged->fresh()->last_retention_sent_at->toDateString())
        ->toBe(Carbon::now()->subDays(5)->toDateString());
});

/*
|--------------------------------------------------------------------------
| Предпросмотр: что именно уйдёт этому клиенту (#91)
|--------------------------------------------------------------------------
*/

it('shows the actual text each client would get on a dry run', function () {
    // С тех пор как рассылка говорит на языке клиента, список одних телефонов
    // не показывает, что уедет: опечатка в uz/kaa-шаблоне уходила незамеченной.
    config(['services.barbershop.retention_days' => 14]);

    Client::factory()->create([
        'phone' => '998901112233',
        'locale' => 'uz',
        'last_visit_at' => Carbon::now()->subDays(14),
        'last_retention_sent_at' => null,
    ]);

    $this->mock(SmsService::class)->shouldNotReceive('sendSms');

    $this->artisan('app:send-retention-messages', ['--dry-run' => true])
        ->expectsOutputToContain(NotificationTemplates::renderSms('retention', [], 'uz'))
        ->assertSuccessful();
});

it('previews each client in their own language, not one text for the run', function () {
    config(['services.barbershop.retention_days' => 14]);

    foreach (['uz', 'kaa'] as $index => $locale) {
        Client::factory()->create([
            'phone' => '99890111223'.$index,
            'locale' => $locale,
            'last_visit_at' => Carbon::now()->subDays(14),
            'last_retention_sent_at' => null,
        ]);
    }

    $this->mock(SmsService::class)->shouldNotReceive('sendSms');

    $this->artisan('app:send-retention-messages', ['--dry-run' => true])
        ->expectsOutputToContain(NotificationTemplates::renderSms('retention', [], 'uz'))
        ->expectsOutputToContain(NotificationTemplates::renderSms('retention', [], 'kaa'))
        ->assertSuccessful();
});

it('sends the single test sms in the stored locale of that number', function () {
    // Иначе живая проверка доставки подтверждает текст на языке салона,
    // который на этот номер рассылка никогда не отправит.
    $client = Client::factory()->create([
        'phone' => '998901112244',
        'locale' => 'kaa',
        'last_visit_at' => Carbon::now()->subDays(21),
        'last_retention_sent_at' => null,
    ]);

    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')
        ->once()
        ->with('998901112244', NotificationTemplates::renderSms('retention', [], 'kaa'), null, 'retention')
        ->andReturnTrue();

    $this->artisan('app:send-retention-messages', ['--phone' => '998901112244'])
        ->assertSuccessful();

    expect($client->fresh()->last_retention_sent_at)->toBeNull();
});

it('falls back to the salon language for a test number that is not a client', function () {
    $this->mock(SmsService::class)
        ->shouldReceive('sendSms')
        ->once()
        ->with('998909998877', NotificationTemplates::renderSms('retention'), null, 'retention')
        ->andReturnTrue();

    $this->artisan('app:send-retention-messages', ['--phone' => '998909998877'])
        ->assertSuccessful();
});
