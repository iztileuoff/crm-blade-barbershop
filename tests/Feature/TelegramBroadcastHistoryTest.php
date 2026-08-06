<?php

use App\Enums\Role;
use App\Jobs\SendTelegramBroadcast;
use App\Models\Client;
use App\Models\TelegramBroadcast;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function broadcastAdmin(): User
{
    return User::factory()->create(['role' => Role::ADMIN]);
}

function linkedBarberUser(int $chatId): User
{
    return User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => $chatId]);
}

// --- Blind confirmation: recipient count and audience must be reused, not guessed ---

it('shows the recipient count and audience for the currently selected audience in the confirmation', function () {
    Client::factory()->create(['telegram_chat_id' => 1001]);
    Client::factory()->create(['telegram_chat_id' => 1002]);
    linkedBarberUser(2001);

    $page = Livewire::actingAs(broadcastAdmin())->test('pages.admin.telegram.broadcast');

    expect($page->instance()->recipientCount)->toBe(2);
    $page->assertSee(__('telegram.broadcast_confirm', [
        'count' => 2,
        'audience' => __('telegram.audience_clients'),
    ]), false);

    $page->set('audience', 'all');
    expect($page->instance()->recipientCount)->toBe(3);
    $page->assertSee(__('telegram.broadcast_confirm', [
        'count' => 3,
        'audience' => __('telegram.audience_all'),
    ]), false);
});

it('confirms the exact recipient count for every audience and dispatches to exactly that set', function () {
    // Число в подтверждении и набор, который реально уйдёт в job, строятся
    // отдельно и сверяются — совпадение числа само по себе ничего не значит,
    // если job отправит не тем chat id.
    Client::factory()->create(['telegram_chat_id' => 8001]);
    Client::factory()->create(['telegram_chat_id' => 8002]);
    Client::factory()->create(['telegram_chat_id' => null]);
    linkedBarberUser(8003);
    User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => null]);

    $expectedByAudience = [
        'clients' => [8001, 8002],
        'barbers' => [8003],
        'all' => [8001, 8002, 8003],
    ];

    foreach ($expectedByAudience as $audience => $expectedChatIds) {
        Queue::fake();

        $page = Livewire::actingAs(broadcastAdmin())
            ->test('pages.admin.telegram.broadcast')
            ->set('audience', $audience);

        expect($page->instance()->recipientCount)->toBe(count($expectedChatIds));
        $page->assertSee(__('telegram.broadcast_confirm', [
            'count' => count($expectedChatIds),
            'audience' => __('telegram.audience_'.$audience),
        ]), false);

        $page->set('message', 'Проверка охвата: '.$audience)
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sentTo', count($expectedChatIds));

        Queue::assertPushed(SendTelegramBroadcast::class, function (SendTelegramBroadcast $job) use ($expectedChatIds) {
            $actual = (fn () => $this->chatIds)->call($job);
            sort($actual);
            $expected = $expectedChatIds;
            sort($expected);

            return $actual === $expected;
        });
    }
});

// --- Persistence: the send must survive a reload, not just a 3.5s banner ---

it('persists a broadcast record before dispatching the job', function () {
    Queue::fake();

    $admin = broadcastAdmin();
    Client::factory()->create(['telegram_chat_id' => 1001]);
    Client::factory()->create(['telegram_chat_id' => 1002]);

    Livewire::actingAs($admin)
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', 'Скидка 20% сегодня')
        ->call('send')
        ->assertHasNoErrors();

    expect(TelegramBroadcast::count())->toBe(1);

    $broadcast = TelegramBroadcast::sole();
    expect($broadcast->user_id)->toBe($admin->id)
        ->and($broadcast->audience)->toBe('clients')
        ->and($broadcast->message)->toBe('Скидка 20% сегодня')
        ->and($broadcast->recipients_count)->toBe(2)
        ->and($broadcast->sent_count)->toBe(0)
        ->and($broadcast->failed_count)->toBe(0)
        ->and($broadcast->completed_at)->toBeNull();

    Queue::assertPushed(SendTelegramBroadcast::class);
});

it('does not create a broadcast record when there are no linked recipients', function () {
    Queue::fake();

    Livewire::actingAs(broadcastAdmin())
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', 'Привет')
        ->call('send')
        ->assertHasErrors('audience');

    expect(TelegramBroadcast::count())->toBe(0);
});

it('shows a completed broadcast with its audience, text, sender and counters after a fresh page mount', function () {
    // Очередь в тестах синхронная (QUEUE_CONNECTION=sync), поэтому send()
    // прогоняет job тут же — как воркер бы сделал в проде. Ни один результат
    // не должен зависеть от баннера, который живёт 3.5 секунды.
    $admin = broadcastAdmin();
    Client::factory()->create(['telegram_chat_id' => 7001]);
    Client::factory()->create(['telegram_chat_id' => 7002]);
    Client::factory()->create(['telegram_chat_id' => 7003]);

    $this->mock(TelegramService::class)
        ->shouldReceive('sendMessage')->times(3)->andReturn(true, true, false);

    Livewire::actingAs($admin)
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', 'Открыты в праздник')
        ->call('send')
        ->assertHasNoErrors();

    $broadcast = TelegramBroadcast::sole();
    expect($broadcast->user_id)->toBe($admin->id)
        ->and($broadcast->audience)->toBe('clients')
        ->and($broadcast->message)->toBe('Открыты в праздник')
        ->and($broadcast->recipients_count)->toBe(3)
        ->and($broadcast->sent_count)->toBe(2)
        ->and($broadcast->failed_count)->toBe(1)
        ->and($broadcast->completed_at)->not->toBeNull();

    // A fresh mount — a new component instance, as a reload would create —
    // must still show the very same result, not just a vanished banner.
    $freshPage = Livewire::actingAs($admin)->test('pages.admin.telegram.broadcast');

    $shown = $freshPage->instance()->recentBroadcasts->firstWhere('id', $broadcast->id);
    expect($shown)->not->toBeNull()
        ->and($shown->sent_count)->toBe(2)
        ->and($shown->failed_count)->toBe(1)
        ->and($shown->isCompleted())->toBeTrue();

    $freshPage->assertSee('Открыты в праздник')
        ->assertSee(__('telegram.audience_clients'))
        ->assertSee($broadcast->sent_count.' '.__('telegram.sent_label'), false)
        ->assertSee($broadcast->failed_count.' '.__('telegram.errors_label'), false)
        ->assertDontSee(__('telegram.status_processing'));
});

// --- The job updates real counters instead of only logging failures ---

it('updates the sent and failed counters from the job and marks it completed', function () {
    $broadcast = TelegramBroadcast::factory()->pending()->create(['recipients_count' => 3]);

    $this->mock(TelegramService::class)
        ->shouldReceive('sendMessage')->times(3)->andReturn(true, false, true);

    (new SendTelegramBroadcast($broadcast->id, [1001, 1002, 1003], 'text'))
        ->handle(app(TelegramService::class));

    $broadcast->refresh();
    expect($broadcast->sent_count)->toBe(2)
        ->and($broadcast->failed_count)->toBe(1)
        ->and($broadcast->completed_at)->not->toBeNull();
});

it('adds up the chunks and completes the broadcast only when everyone is accounted for', function () {
    // Рассылка идёт пачками, чтобы задание не пережило retry_after очереди и не
    // было выдано второму воркеру — значит счётчики складываются, а «завершено»
    // ставится, когда отчитались все получатели, а не первая же пачка.
    $broadcast = TelegramBroadcast::factory()->pending()->create(['recipients_count' => 4]);

    $this->mock(TelegramService::class)
        ->shouldReceive('sendMessage')->times(4)->andReturn(true, false, true, true);

    (new SendTelegramBroadcast($broadcast->id, [1001, 1002], 'text'))->handle(app(TelegramService::class));

    $broadcast->refresh();
    expect($broadcast->sent_count)->toBe(1)
        ->and($broadcast->failed_count)->toBe(1)
        ->and($broadcast->completed_at)->toBeNull();

    (new SendTelegramBroadcast($broadcast->id, [1003, 1004], 'text'))->handle(app(TelegramService::class));

    $broadcast->refresh();
    expect($broadcast->sent_count)->toBe(3)
        ->and($broadcast->failed_count)->toBe(1)
        ->and($broadcast->completed_at)->not->toBeNull();
});

it('never retries a chunk, and books a dead chunk as failures instead of leaving it processing', function () {
    // Отправка не идемпотентна: вторая попытка — это второе сообщение живому
    // человеку, поэтому повторов нет, а упавшая пачка закрывает свою часть.
    $job = new SendTelegramBroadcast(1, [1001, 1002], 'text');
    expect($job->tries)->toBe(1);

    $broadcast = TelegramBroadcast::factory()->pending()->create(['recipients_count' => 2]);

    (new SendTelegramBroadcast($broadcast->id, [1001, 1002], 'text'))->failed(null);

    $broadcast->refresh();
    expect($broadcast->sent_count)->toBe(0)
        ->and($broadcast->failed_count)->toBe(2)
        ->and($broadcast->completed_at)->not->toBeNull();
});

it('splits a large audience into chunks that cannot outlive the queue retry window', function () {
    Queue::fake();

    Client::factory()->count(SendTelegramBroadcast::CHUNK + 3)->create([
        'telegram_chat_id' => null,
    ])->each(fn (Client $client, int $i) => $client->update(['telegram_chat_id' => 9000 + $i]));

    Livewire::actingAs(broadcastAdmin())
        ->test('pages.admin.telegram.broadcast')
        ->set('audience', 'clients')
        ->set('message', 'Большая рассылка')
        ->call('send')
        ->assertHasNoErrors();

    Queue::assertPushed(SendTelegramBroadcast::class, 2);
});

// --- "Последние рассылки" card ---

it('lists recent broadcasts newest first and paginates them', function () {
    TelegramBroadcast::factory()->count(12)->sequence(
        fn ($s) => ['created_at' => now()->subMinutes(12 - $s->index)]
    )->create();

    $page = Livewire::actingAs(broadcastAdmin())->test('pages.admin.telegram.broadcast');

    expect($page->instance()->recentBroadcasts->total())->toBe(12)
        ->and($page->instance()->recentBroadcasts)->toHaveCount(10);

    // Most recently created broadcast (the last in the sequence) leads the list.
    expect($page->instance()->recentBroadcasts->first()->id)
        ->toBe(TelegramBroadcast::latest()->first()->id);
});

it('shows a processing state for a broadcast the job has not finished yet', function () {
    TelegramBroadcast::factory()->pending()->create(['message' => 'В процессе рассылка']);

    Livewire::actingAs(broadcastAdmin())
        ->test('pages.admin.telegram.broadcast')
        ->assertSee(__('telegram.status_processing'));
});

// --- Queue health: "queued" must not lie about a dead worker ---

it('warns when the database queue has a stale backlog', function () {
    config(['queue.default' => 'database']);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => 'noop',
        'attempts' => 0,
        'available_at' => now()->subMinutes(10)->timestamp,
        'created_at' => now()->subMinutes(10)->timestamp,
    ]);

    $page = Livewire::actingAs(broadcastAdmin())->test('pages.admin.telegram.broadcast');

    expect($page->instance()->queueStalled)->toBeTrue();
    $page->assertSee(__('telegram.queue_stalled', ['count' => 1, 'minutes' => 10]));
});

it('does not warn about the queue when there is no backlog', function () {
    config(['queue.default' => 'database']);

    $page = Livewire::actingAs(broadcastAdmin())->test('pages.admin.telegram.broadcast');

    expect($page->instance()->queueStalled)->toBeFalse();
});

it('does not run a fake queue check on a driver it cannot inspect', function () {
    config(['queue.default' => 'sync']);

    // Even a stale-looking row must not trigger a warning: sync has no backlog
    // concept, so checking this table for it would be a fabricated signal.
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => 'noop',
        'attempts' => 0,
        'available_at' => now()->subHour()->timestamp,
        'created_at' => now()->subHour()->timestamp,
    ]);

    $page = Livewire::actingAs(broadcastAdmin())->test('pages.admin.telegram.broadcast');

    expect($page->instance()->queueCheckSupported)->toBeFalse()
        ->and($page->instance()->queueStalled)->toBeFalse();
    $page->assertDontSee(__('telegram.queue_stalled', ['count' => 1, 'minutes' => 60]));
});

// --- A barber has no business sending a broadcast ---

it('refuses a barber that reaches the component directly, not just the route', function () {
    // Маршрутный middleware на update-запросах Livewire не переигрывается,
    // поэтому страница массовой рассылки обязана сторожить себя сама.
    Livewire::actingAs(linkedBarberUser(7777))
        ->test('pages.admin.telegram.broadcast')
        ->assertForbidden();
});

it('redirects a barber away from the broadcast page instead of letting them send one', function () {
    Queue::fake();
    $barberUser = User::factory()->create(['role' => Role::BARBER]);
    Client::factory()->create(['telegram_chat_id' => 9001]);

    $this->actingAs($barberUser)
        ->get(route('admin.telegram.broadcast'))
        ->assertRedirect(route('admin.appointments'));

    expect(TelegramBroadcast::count())->toBe(0);
    Queue::assertNotPushed(SendTelegramBroadcast::class);
});
