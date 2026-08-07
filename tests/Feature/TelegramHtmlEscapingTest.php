<?php

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Jobs\SendTelegramBroadcast;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use App\Telegram\AppointmentFormatter;
use App\Telegram\TelegramHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('leaves an apostrophe alone — telegram does not decode &#039;', function () {
    // Апостроф в узбекских именах — правило, а не редкость: e() кодирует его
    // в числовую сущность, а Telegram понимает только &lt; &gt; &amp; &quot;.
    expect(TelegramHtml::escape("O'ktam"))->toBe("O'ktam");
});

it('still escapes the characters that would open a tag', function () {
    expect(TelegramHtml::escape('<b>Скидка</b> & <i>всё</i>'))
        ->toBe('&lt;b&gt;Скидка&lt;/b&gt; &amp; &lt;i&gt;всё&lt;/i&gt;');
});

it('treats null as an empty string', function () {
    expect(TelegramHtml::escape(null))->toBe('');
});

it('keeps an apostrophe in a client name intact in the barber\'s schedule line', function () {
    $client = Client::factory()->create(['name' => "O'ktam Sa'dullayev"]);
    $barber = Barber::factory()->create();

    $appointment = Appointment::create([
        'client_id' => $client->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->addDay()->setTime(12, 0),
        'ends_at' => now()->addDay()->setTime(13, 0),
        'status' => AppointmentStatus::Confirmed,
        'price' => 50000,
    ]);

    expect(AppointmentFormatter::barberLine($appointment))
        ->toContain("O'ktam Sa'dullayev")
        ->not->toContain('&#039;');
});

it('keeps an apostrophe in the barber name intact in the client\'s line', function () {
    $barber = Barber::factory()->create(['name' => "G'ani"]);

    $appointment = Appointment::create([
        'client_id' => Client::factory()->create()->id,
        'barber_id' => $barber->id,
        'starts_at' => now()->addDay()->setTime(12, 0),
        'ends_at' => now()->addDay()->setTime(13, 0),
        'status' => AppointmentStatus::Confirmed,
        'price' => 50000,
    ]);

    expect(AppointmentFormatter::clientLine($appointment))
        ->toContain("G'ani")
        ->not->toContain('&#039;');
});

it('queues the broadcast text with the apostrophe the operator typed', function () {
    Queue::fake();
    Client::factory()->create(['telegram_chat_id' => 999001]);

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', "Завтра работаем с 10:00, ждём! Don't be late")
        ->call('send')
        ->assertHasNoErrors();

    Queue::assertPushed(
        SendTelegramBroadcast::class,
        fn (SendTelegramBroadcast $job) => str_contains((fn () => $this->text)->call($job), "Don't be late"),
    );

    Queue::assertNotPushed(
        SendTelegramBroadcast::class,
        fn (SendTelegramBroadcast $job) => str_contains((fn () => $this->text)->call($job), '&#039;'),
    );
});

it('still strips markup an operator pasted into the broadcast', function () {
    Queue::fake();
    Client::factory()->create(['telegram_chat_id' => 999002]);

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', '<script>alert(1)</script>')
        ->call('send')
        ->assertHasNoErrors();

    Queue::assertPushed(
        SendTelegramBroadcast::class,
        fn (SendTelegramBroadcast $job) => str_contains((fn () => $this->text)->call($job), '&lt;script&gt;'),
    );
});

it('seeds the character counter with characters, not bytes', function () {
    // strlen на кириллице давал вдвое больше символов, и перерисованный после
    // ошибки валидации черновик показывал «2400/2000» красным на валидном
    // сообщении.
    $message = str_repeat('я', 1200);

    Livewire::actingAs(User::factory()->create(['role' => Role::ADMIN]))
        ->test('pages.admin.telegram.broadcast')
        ->set('message', $message)
        ->assertSee('n: 1200', escape: false)
        ->assertDontSee('n: 2400', escape: false);
});
