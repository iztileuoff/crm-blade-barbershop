<?php

namespace App\Telegram;

use App\Models\Client;
use App\Models\User;

/**
 * Связывает Telegram-чат с профилем в CRM по номеру телефона и
 * разрешает, кто стоит за конкретным chat_id (мастер или клиент).
 */
class TelegramLinker
{
    /**
     * Мастер (пользователь с ролью barber), привязавший данный чат.
     */
    public function findBarberUserByChat(int $chatId): ?User
    {
        return User::query()
            ->where('telegram_chat_id', $chatId)
            ->get()
            ->first(fn (User $user): bool => $user->isBarber());
    }

    /**
     * Клиент, привязавший данный чат.
     */
    public function findClientByChat(int $chatId): ?Client
    {
        return Client::query()->where('telegram_chat_id', $chatId)->first();
    }

    public function isLinked(int $chatId): bool
    {
        return $this->findBarberUserByChat($chatId) !== null
            || $this->findClientByChat($chatId) !== null;
    }

    /**
     * Привязать чат к профилю по номеру телефона.
     *
     * Приоритет — мастер (users), затем клиент (clients). Если клиента нет в
     * базе, создаём запись, чтобы он сразу получал уведомления и историю.
     */
    public function linkByPhone(int $chatId, string $rawPhone, ?string $name = null): LinkOutcome
    {
        $normalized = Client::normalizePhone($rawPhone);

        if ($normalized === null) {
            return LinkOutcome::InvalidPhone;
        }

        $this->clearChat($chatId);

        $barberUser = User::query()
            ->where('phone', $normalized)
            ->get()
            ->first(fn (User $user): bool => $user->isBarber());

        if ($barberUser !== null) {
            $barberUser->forceFill(['telegram_chat_id' => $chatId])->save();

            return LinkOutcome::BarberLinked;
        }

        $client = Client::query()->where('phone', $normalized)->first();

        if ($client !== null) {
            $client->forceFill(['telegram_chat_id' => $chatId])->save();

            return LinkOutcome::ClientLinked;
        }

        Client::create([
            'name' => $name !== null && trim($name) !== '' ? trim($name) : 'Клиент',
            'phone' => $normalized,
            'telegram_chat_id' => $chatId,
        ]);

        return LinkOutcome::ClientCreated;
    }

    /**
     * Снять привязку чата с любых профилей (чтобы chat_id оставался уникальным).
     */
    private function clearChat(int $chatId): void
    {
        User::query()->where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null]);
        Client::query()->where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null]);
    }
}
