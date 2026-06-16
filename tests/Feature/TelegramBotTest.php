<?php

use App\Enums\Role;
use App\Models\Barber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;

uses(RefreshDatabase::class);

function botWithHandlers(): Nutgram
{
    $bot = Nutgram::fake();

    (function () use ($bot) {
        require base_path('routes/telegram.php');
    })();

    return $bot;
}

it('prompts an unknown user to share contact on /start', function () {
    $bot = botWithHandlers();

    $bot->hearText('/start')->reply();

    $bot->assertReply('sendMessage');
});

it('shows the barber menu when an already linked barber sends /start', function () {
    $user = User::factory()->create(['role' => Role::BARBER, 'telegram_chat_id' => 424242]);
    Barber::factory()->create(['user_id' => $user->id]);

    $bot = botWithHandlers();
    $bot->setCommonChat(Chat::make(
        id: 424242,
        type: ChatType::PRIVATE,
    ));

    $bot->hearText('/start')->reply();

    $bot->assertReply('sendMessage');
});

it('links a barber when they share their own contact', function () {
    $user = User::factory()->create(['role' => Role::BARBER, 'phone' => '998906591828']);
    Barber::factory()->create(['user_id' => $user->id]);

    $bot = botWithHandlers();

    $bot->hearUpdateType(UpdateType::MESSAGE, [
        'from' => ['id' => 700, 'is_bot' => false, 'first_name' => 'Иван'],
        'contact' => ['phone_number' => '998906591828', 'user_id' => 700, 'first_name' => 'Иван'],
    ])->reply();

    expect($user->fresh()->telegram_chat_id)->not->toBeNull();
    $bot->assertReply('sendMessage');
});
