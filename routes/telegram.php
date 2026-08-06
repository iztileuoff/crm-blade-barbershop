<?php

/** @var Nutgram $bot */

use App\Telegram\Handlers\BarberMenuHandler;
use App\Telegram\Handlers\CancelAppointmentHandler;
use App\Telegram\Handlers\ClientMenuHandler;
use App\Telegram\Handlers\ContactHandler;
use App\Telegram\Handlers\StartHandler;
use App\Telegram\Keyboards;
use App\Telegram\Middleware\SetBotLocale;
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

// Язык интерфейса — под собеседника, на каждое обновление (#76): у webhook'а,
// очереди и планировщика нет сессии, поэтому это обязано случиться до любого
// хэндлера, а не полагаться на app()->getLocale() по умолчанию.
$bot->middleware(SetBotLocale::class);

$bot->onCommand('start', StartHandler::class)->description('Запустить бота / привязать профиль');

$bot->onContact(ContactHandler::class);

// Отмена записи клиентом — инлайн-кнопка под каждой записью (см. ClientMenuHandler::appointments()).
$bot->onCallbackQueryData('cancel:{id}', CancelAppointmentHandler::class)->whereNumber('id');

// Меню мастера
foreach (Keyboards::labelVariants(Keyboards::BARBER_TODAY) as $label) {
    $bot->onText($label, [BarberMenuHandler::class, 'today']);
}
foreach (Keyboards::labelVariants(Keyboards::BARBER_TOMORROW) as $label) {
    $bot->onText($label, [BarberMenuHandler::class, 'tomorrow']);
}
foreach (Keyboards::labelVariants(Keyboards::BARBER_EARNINGS) as $label) {
    $bot->onText($label, [BarberMenuHandler::class, 'earnings']);
}

// Меню клиента — та же логика: кнопка приходит на языке клиента, а маршрут
// зарегистрирован один раз при загрузке, поэтому слушаем все варианты сразу.
foreach (Keyboards::labelVariants(Keyboards::CLIENT_APPOINTMENTS) as $label) {
    $bot->onText($label, [ClientMenuHandler::class, 'appointments']);
}
foreach (Keyboards::labelVariants(Keyboards::CLIENT_HISTORY) as $label) {
    $bot->onText($label, [ClientMenuHandler::class, 'history']);
}
foreach (Keyboards::labelVariants(Keyboards::CLIENT_DEBT) as $label) {
    $bot->onText($label, [ClientMenuHandler::class, 'debt']);
}

// Любое непонятное сообщение — показать подходящее меню / попросить привязаться
$bot->fallback(StartHandler::class);
