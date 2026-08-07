<?php

namespace App\Telegram;

class TelegramHtml
{
    /**
     * Экранирование значения для `parse_mode: HTML`.
     *
     * Не `e()`: он зовёт htmlspecialchars с ENT_QUOTES и кодирует апостроф в
     * `&#039;`, а Telegram из сущностей понимает только `&lt;` `&gt;` `&amp;`
     * `&quot;` — числовые он оставляет как есть, и клиент читает «O&#039;ktam»
     * вместо «O'ktam». Апострофы в узбекских именах — правило, а не редкость.
     *
     * ENT_NOQUOTES безопасен: раз `<` и `>` закодированы, тега не соберётся, а
     * значит и кавычка внутри атрибута ничего не откроет.
     */
    public static function escape(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');
    }
}
