<?php

namespace App\Telegram\Middleware;

use App\Support\NotificationTemplates;
use App\Telegram\TelegramLinker;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;

/**
 * Выставляет язык интерфейса бота под собеседника до вызова хэндлера.
 *
 * У webhook'а, очереди и планировщика нет сессии — без этого app()->getLocale()
 * всегда возвращал бы только конфиг по умолчанию, и бот отвечал бы всем
 * по-русски независимо от того, на каком языке клиент бронировал (#76).
 *
 * Привязанный клиент получает свой clients.locale; мастер (для него личного
 * языка не хранится) и непривязанный чат — язык SMS салона из настроек, тот
 * же откат, что и у {@see NotificationTemplates::renderSms()}.
 */
class SetBotLocale
{
    public function __construct(private readonly TelegramLinker $linker) {}

    public function __invoke(Nutgram $bot, $next): void
    {
        $chatId = $bot->chatId();
        $client = $chatId !== null ? $this->linker->findClientByChat($chatId) : null;

        $locale = NotificationTemplates::localeFor($client?->locale);

        app()->setLocale($locale);
        Carbon::setLocale($locale === 'kaa' ? 'uz' : $locale);

        $next($bot);
    }
}
