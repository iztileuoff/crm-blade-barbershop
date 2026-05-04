<?php

namespace App\Services;

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
     * Отправить SMS клиенту.
     *
     * @param  string  $phone  Номер в формате 998XXXXXXXXX
     * @param  string  $message  Текст сообщения (только латиница допустима для Eskiz при бесплатном тарифе)
     */
    public function sendSms(string $phone, string $message): bool
    {
        if ($this->email === '' || $this->password === '') {
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

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Исключение при отправке SMS Eskiz', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
