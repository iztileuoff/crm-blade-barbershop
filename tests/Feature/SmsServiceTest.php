<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

it('does not send or log when the SMS type is disabled in settings', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['status' => 'success'], 200),
    ]);

    Setting::set('sms_enabled_reminder', '0');

    $result = configuredSms()->sendSms('998901234567', 'Привет', null, 'reminder');

    expect($result)->toBeFalse()
        ->and(SmsMessage::count())->toBe(0);

    Http::assertNothingSent();
});

it('still sends contexts that have no toggle when others are disabled', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['status' => 'success'], 200),
    ]);

    Setting::set('sms_enabled_reminder', '0');
    Setting::set('sms_enabled_retention', '0');

    $result = configuredSms()->sendSms('998901234567', 'Привет', null, 'manual');

    expect($result)->toBeTrue()
        ->and(SmsMessage::where('context', 'manual')->count())->toBe(1);
});

it('skips retention SMS when retention is disabled', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['status' => 'success'], 200),
    ]);

    configuredSms();
    config(['services.barbershop.retention_days' => 14]);
    Setting::set('sms_enabled_retention', '0');

    Client::factory()->create([
        'phone' => '998901234567',
        'last_visit_at' => Carbon::now()->subDays(14)->setTime(12, 0),
        'last_retention_sent_at' => null,
    ]);

    $this->artisan('app:send-retention-messages')->assertSuccessful();

    expect(SmsMessage::count())->toBe(0);
    Http::assertNothingSent();
});

it('persists the dispatch toggles from the settings page', function () {
    configuredSms();

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.sms.settings')
        ->assertSet('remindersEnabled', true)
        ->assertSet('retentionEnabled', true)
        ->set('remindersEnabled', false);

    expect(Setting::get('sms_enabled_reminder'))->toBe('0');
});

it('persists the SMS language from the settings page', function () {
    configuredSms();

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.sms.settings')
        ->assertSet('smsLocale', 'ru')
        ->set('smsLocale', 'uz');

    expect(Setting::get('sms_locale'))->toBe('uz');
});

it('uses the fixed retention template and logs context for retention SMS', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['id' => '777', 'status' => 'waiting'], 200),
    ]);

    configuredSms();
    config(['services.barbershop.retention_days' => 14]);

    Client::factory()->create([
        'phone' => '998901234567',
        'last_visit_at' => Carbon::now()->subDays(14)->setTime(12, 0),
        'last_retention_sent_at' => null,
    ]);

    $this->artisan('app:send-retention-messages')->assertSuccessful();

    $message = SmsMessage::firstOrFail();
    expect($message->context)->toBe('retention')
        ->and($message->message)->toBe('Давно вас не видели! Время обновить стрижку. Blade Barbershop.')
        ->and($message->eskiz_message_id)->toBe('777');
});

it('stores the Eskiz message id and SMS parts on send', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/send' => Http::response(['id' => '12345', 'status' => 'waiting'], 200),
    ]);

    configuredSms()->sendSms('998901234567', 'Salom', null, 'manual');

    $message = SmsMessage::firstOrFail();
    expect($message->eskiz_message_id)->toBe('12345')
        ->and($message->parts)->toBe(1);
});

it('renders SMS templates in the language chosen in settings', function () {
    expect(NotificationTemplates::renderSms('reminder', ['time' => '18:00']))
        ->toBe('Напоминаем, вы записаны на 18:00. Ждем вас в Blade Barbershop!');

    Setting::set('sms_locale', 'uz');

    expect(NotificationTemplates::renderSms('reminder', ['time' => '18:00']))
        ->toBe("Eslatma: Blade Barbershop'da soat 18:00 ga yozilgansiz. Sizni kutamiz!");

    Setting::set('sms_locale', 'kaa');

    expect(NotificationTemplates::renderSms('retention'))
        ->toBe('Uzaq waqıt kelmedińiz! Shash aldırıw waqtı keldi. Blade Barbershop.');
});

it('falls back to the default language for an unknown sms_locale', function () {
    Setting::set('sms_locale', 'xx');

    expect(NotificationTemplates::smsLocale())->toBe('ru')
        ->and(NotificationTemplates::renderSms('retention'))
        ->toBe('Давно вас не видели! Время обновить стрижку. Blade Barbershop.');
});

it('updates the delivery status from Eskiz', function () {
    Http::fake([
        '*auth/login' => Http::response(['data' => ['token' => 'tok']], 200),
        '*message/sms/status_by_id/*' => Http::response(['data' => ['status' => 'DELIVRD']], 200),
    ]);

    configuredSms();

    $message = SmsMessage::factory()->create([
        'status' => 'sent',
        'eskiz_message_id' => '999',
        'delivery_status' => null,
    ]);

    $this->artisan('app:sync-sms-statuses')->assertSuccessful();

    expect($message->fresh()->delivery_status)->toBe('delivered');
});
