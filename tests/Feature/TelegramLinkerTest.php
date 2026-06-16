<?php

use App\Enums\Role;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use App\Telegram\LinkOutcome;
use App\Telegram\TelegramLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->linker = new TelegramLinker;
});

it('links a barber user by phone', function () {
    $user = User::factory()->create(['role' => Role::BARBER, 'phone' => '998906591828']);
    Barber::factory()->create(['user_id' => $user->id]);

    $outcome = $this->linker->linkByPhone(555000111, '+998 90 659 18 28', 'Иван');

    expect($outcome)->toBe(LinkOutcome::BarberLinked)
        ->and($user->fresh()->telegram_chat_id)->toBe(555000111)
        ->and($this->linker->findBarberUserByChat(555000111)?->id)->toBe($user->id);
});

it('links an existing client by phone', function () {
    $client = Client::factory()->create(['phone' => '998901112233']);

    $outcome = $this->linker->linkByPhone(777, '998901112233', 'Пётр');

    expect($outcome)->toBe(LinkOutcome::ClientLinked)
        ->and($client->fresh()->telegram_chat_id)->toBe(777)
        ->and($this->linker->findClientByChat(777)?->id)->toBe($client->id);
});

it('creates a client when the phone is unknown', function () {
    $outcome = $this->linker->linkByPhone(888, '998905554433', 'Новый Клиент');

    expect($outcome)->toBe(LinkOutcome::ClientCreated);

    $client = Client::where('phone', '998905554433')->first();

    expect($client)->not->toBeNull()
        ->and($client->name)->toBe('Новый Клиент')
        ->and($client->telegram_chat_id)->toBe(888);
});

it('rejects an unparseable phone', function () {
    expect($this->linker->linkByPhone(999, 'not-a-phone'))->toBe(LinkOutcome::InvalidPhone);
});

it('moves a chat id to a new profile, clearing the old link', function () {
    $client = Client::factory()->create(['phone' => '998901112233', 'telegram_chat_id' => 1234]);
    $other = Client::factory()->create(['phone' => '998907778899']);

    $this->linker->linkByPhone(1234, '998907778899');

    expect($client->fresh()->telegram_chat_id)->toBeNull()
        ->and($other->fresh()->telegram_chat_id)->toBe(1234);
});
