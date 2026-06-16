<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function configuredSms(): SmsService
{
    config([
        'services.eskiz.email' => 'shop@example.com',
        'services.eskiz.password' => 'secret',
        'services.eskiz.base_url' => 'https://notify.eskiz.uz/api',
        'services.eskiz.from' => '4546',
    ]);

    app()->forgetInstance(SmsService::class);

    return app(SmsService::class);
}

it('logs a sent SMS to history', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['status' => 'success'], 200),
    ]);

    $result = configuredSms()->sendSms('998901234567', 'Привет', null, 'manual');

    expect($result)->toBeTrue();

    $message = SmsMessage::firstOrFail();
    expect($message->status)->toBe('sent')
        ->and($message->context)->toBe('manual')
        ->and($message->phone)->toBe('998901234567');
});

it('logs a failed SMS to history', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['message' => 'error'], 500),
    ]);

    $result = configuredSms()->sendSms('998901234567', 'Привет', null, 'manual');

    expect($result)->toBeFalse()
        ->and(SmsMessage::where('status', 'failed')->count())->toBe(1);
});

it('does not log or send when Eskiz is not configured', function () {
    config(['services.eskiz.email' => '', 'services.eskiz.password' => '']);
    app()->forgetInstance(SmsService::class);

    expect(app(SmsService::class)->sendSms('998901234567', 'Привет'))->toBeFalse()
        ->and(SmsMessage::count())->toBe(0);
});

it('checks the Eskiz connection and reports the balance', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*user/get-limit' => Http::response(['data' => ['balance' => 12345]], 200),
    ]);

    configuredSms();

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.sms.settings')
        ->assertSet('configured', true)
        ->call('check')
        ->assertSet('connectionOk', true)
        ->assertSet('balance', '12345');
});

it('uses the editable template and logs context for retention SMS', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['status' => 'success'], 200),
    ]);

    configuredSms();
    NotificationTemplates::set('sms_retention', 'Прошло {days} дней — ждём вас!');

    Client::factory()->create([
        'phone' => '998901234567',
        'last_visit_at' => now()->subDays(21),
        'last_retention_sent_at' => null,
    ]);

    $this->artisan('app:send-retention-messages')->assertSuccessful();

    $message = SmsMessage::firstOrFail();
    expect($message->context)->toBe('retention')
        ->and($message->message)->toBe('Прошло 21 дней — ждём вас!');
});
