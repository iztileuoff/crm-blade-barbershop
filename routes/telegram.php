<?php

/** @var Nutgram $bot */

use App\Telegram\Handlers\BarberMenuHandler;
use App\Telegram\Handlers\ClientMenuHandler;
use App\Telegram\Handlers\ContactHandler;
use App\Telegram\Handlers\StartHandler;
use App\Telegram\Keyboards;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Один бот на всех: роль определяется по привязанному профилю.
| Привязка — по номеру телефона (кнопка «Поделиться номером»).
|
*/

$bot->onCommand('start', StartHandler::class)->description('Запустить бота / привязать профиль');

$bot->onContact(ContactHandler::class);

// Меню мастера
$bot->onText(Keyboards::BARBER_TODAY, [BarberMenuHandler::class, 'today']);
$bot->onText(Keyboards::BARBER_TOMORROW, [BarberMenuHandler::class, 'tomorrow']);
$bot->onText(Keyboards::BARBER_EARNINGS, [BarberMenuHandler::class, 'earnings']);

// Меню клиента
$bot->onText(Keyboards::CLIENT_APPOINTMENTS, [ClientMenuHandler::class, 'appointments']);
$bot->onText(Keyboards::CLIENT_HISTORY, [ClientMenuHandler::class, 'history']);
$bot->onText(Keyboards::CLIENT_DEBT, [ClientMenuHandler::class, 'debt']);

// Любое непонятное сообщение — показать подходящее меню / попросить привязаться
$bot->fallback(StartHandler::class);
