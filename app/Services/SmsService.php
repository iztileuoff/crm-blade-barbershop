<?php

namespace App\Services;

use App\Models\SmsMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SmsService
{
    private const TOKEN_CACHE_KEY = 'eskiz.token';

    private const TOKEN_TTL_MINUTES = 60 * 24 * 25; // ~25 дней (токен живёт 30)

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $email,
        private readonly string $password,
        private readonly string $from,
    ) {}

    /**
     * Настроены ли учётные данные Eskiz (логин/пароль).
     */
    public function isConfigured(): bool
    {
        return $this->email !== '' && $this->password !== '';
    }

    public function from(): string
    {
        return $this->from;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Отправить SMS клиенту. Любая попытка (успех или ошибка) пишется в историю.
     *
     * @param  string  $phone  Номер в формате 998XXXXXXXXX
     * @param  string  $message  Текст сообщения (только латиница допустима для Eskiz при бесплатном тарифе)
     * @param  int|null  $clientId  Связанный клиент (для истории), если есть
     * @param  string  $context  Источник: reminder | retention | broadcast | manual
     */
    public function sendSms(string $phone, string $message, ?int $clientId = null, string $context = 'manual'): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('SMS не отправлено: учётные данные Eskiz не настроены.', [
                'phone' => $phone,
            ]);

            return false;
        }

        try {
            $response = $this->client()->asMultipart()->post('/message/sms/send', [
                ['name' => 'mobile_phone', 'contents' => $phone],
                ['name' => 'message', 'contents' => $message],
                ['name' => 'from', 'contents' => $this->from],
            ]);

            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                $response = $this->client()->asMultipart()->post('/message/sms/send', [
                    ['name' => 'mobile_phone', 'contents' => $phone],
                    ['name' => 'message', 'contents' => $message],
                    ['name' => 'from', 'contents' => $this->from],
                ]);
            }

            if (! $response->successful()) {
                Log::error('Ошибка отправки SMS Eskiz', [
                    'phone' => $phone,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $this->record($phone, $message, 'failed', $clientId, $context);

                return false;
            }

            $this->record($phone, $message, 'sent', $clientId, $context);

            return true;
        } catch (\Throwable $e) {
            Log::error('Исключение при отправке SMS Eskiz', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            $this->record($phone, $message, 'failed', $clientId, $context);

            return false;
        }
    }

    /**
     * Проверить, что Eskiz принимает учётные данные (получает токен).
     */
    public function checkConnection(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            Cache::forget(self::TOKEN_CACHE_KEY);

            return $this->token() !== '';
        } catch (\Throwable $e) {
            Log::warning('Проверка подключения Eskiz не удалась', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Остаток средств на счёте Eskiz, либо null если получить не удалось.
     */
    public function balance(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client()->get('/user/get-limit');

            if (! $response->successful()) {
                return null;
            }

            $balance = $response->json('data.balance');

            return $balance !== null ? (string) $balance : null;
        } catch (\Throwable $e) {
            Log::warning('Не удалось получить баланс Eskiz', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function record(string $phone, string $message, string $status, ?int $clientId, string $context): void
    {
        SmsMessage::create([
            'client_id' => $clientId,
            'phone' => $phone,
            'message' => $message,
            'status' => $status,
            'context' => $context,
        ]);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(15)
            ->connectTimeout(5)
            ->withToken($this->token())
            ->acceptJson();
    }

    /**
     * JWT-токен с кэшированием в Laravel Cache.
     */
    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(self::TOKEN_TTL_MINUTES), function (): string {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(10)
                ->connectTimeout(5)
                ->asMultipart()
                ->acceptJson()
                ->post('/auth/login', [
                    ['name' => 'email', 'contents' => $this->email],
                    ['name' => 'password', 'contents' => $this->password],
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Не удалось получить токен Eskiz: '.$response->body());
            }

            $token = $response->json('data.token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Eskiz вернул пустой токен.');
            }

            return $token;
        });
    }
}
