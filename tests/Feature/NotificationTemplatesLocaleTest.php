<?php

use App\Models\Setting;
use App\Support\NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the salon-wide sms locale when no recipient locale is given', function () {
    Setting::set('sms_locale', 'uz');

    expect(NotificationTemplates::localeFor(null))->toBe('uz')
        ->and(NotificationTemplates::renderSms('reminder', ['time' => '14:00']))
        ->toBe(str_replace('{time}', '14:00', NotificationTemplates::SMS['reminder']['uz']));
});

it('prefers the recipient locale over the salon default', function () {
    Setting::set('sms_locale', 'ru');

    expect(NotificationTemplates::localeFor('kaa'))->toBe('kaa')
        ->and(NotificationTemplates::renderSms('reminder', ['time' => '14:00'], 'kaa'))
        ->toBe(str_replace('{time}', '14:00', NotificationTemplates::SMS['reminder']['kaa']));
});

it('falls back to the salon default for an unsupported or missing recipient locale', function () {
    Setting::set('sms_locale', 'uz');

    expect(NotificationTemplates::localeFor('fr'))->toBe('uz')
        ->and(NotificationTemplates::localeFor(null))->toBe('uz');
});
