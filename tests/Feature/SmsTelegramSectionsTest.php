<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Jobs\SendTelegramBroadcast;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\SmsMessage;
use App\Models\User;
use App\Support\NotificationTemplates;
use App\Telegram\AppointmentFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function admin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

it('shows the SMS and Telegram groups in the navigation', function () {
    $this->actingAs(admin())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('SMS')
        ->assertSee('Telegram');
});

it('saves SMS templates', function () {
    Livewire::actingAs(admin())
        ->test('pages.admin.sms.templates')
        ->set('values.sms_reminder', 'Ваша запись в {time}')
        ->set('values.sms_retention', 'Прошло {days} дней')
        ->call('save')
        ->assertHasNoErrors();

    expect(NotificationTemplates::get('sms_reminder'))->toBe('Ваша запись в {time}')
        ->and(NotificationTemplates::get('sms_retention'))->toBe('Прошло {days} дней');
});

it('rejects empty SMS templates', function () {
    Livewire::actingAs(admin())
        ->test('pages.admin.sms.templates')
        ->set('values.sms_reminder', '')
        ->call('save')
        ->assertHasErrors('values.sms_reminder');
});

it('saves Telegram templates and the formatter uses them', function () {
    Livewire::actingAs(admin())
        ->test('pages.admin.telegram.templates')
        ->set('values.tg_reminder_for_client', 'Скоро визит в {time} к {barber}')
        ->call('save')
        ->assertHasNoErrors();

    $barber = Barber::factory()->create(['name' => 'Иван']);
    $client = Client::factory()->create();
    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->setTime(14, 0),
        'ends_at' => now()->setTime(15, 0),
        'status' => AppointmentStatus::Confirmed,
        'price' => 50000,
    ]);

    expect(AppointmentFormatter::reminderForClient($appointment))->toBe('Скоро визит в 14:00 к Иван');
});

it('lists SMS history with sent and failed counts', function () {
    SmsMessage::factory()->count(2)->create(['status' => 'sent']);
    SmsMessage::factory()->failed()->create();

    Livewire::actingAs(admin())
        ->test('pages.admin.sms.history')
        ->assertSet('sentCount', 2)
        ->assertSet('failedCount', 1);
});

it('queues a Telegram broadcast to linked clients', function () {
    Queue::fake();

    Client::factory()->create(['telegram_chat_id' => 1001]);
    Client::factory()->create(['telegram_chat_id' => 1002]);
    Client::factory()->create(['telegram_chat_id' => null]);

    Livewire::actingAs(admin())
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', 'Завтра работаем с 10:00')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sentTo', 2);

    Queue::assertPushed(SendTelegramBroadcast::class);
});

it('does not broadcast when there are no linked recipients', function () {
    Queue::fake();

    Livewire::actingAs(admin())
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', 'Привет')
        ->call('send')
        ->assertHasErrors('audience');

    Queue::assertNotPushed(SendTelegramBroadcast::class);
});

it('unlinks a client from Telegram', function () {
    $client = Client::factory()->create(['telegram_chat_id' => 2001]);

    Livewire::actingAs(admin())
        ->test('pages.admin.telegram.linked')
        ->assertSee($client->name)
        ->call('unlinkClient', $client->id);

    expect($client->fresh()->telegram_chat_id)->toBeNull();
});

it('unlinks a barber from Telegram', function () {
    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 3001]);
    Barber::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs(admin())
        ->test('pages.admin.telegram.linked')
        ->call('unlinkBarber', $user->id);

    expect($user->fresh()->telegram_chat_id)->toBeNull();
});
