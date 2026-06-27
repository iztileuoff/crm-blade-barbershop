<?php

namespace App\Services;

use App\Models\Setting;
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

    /**
     * Контексты, отправку которых можно отключать в настройках, и ключ-флаг в settings.
     * Контексты без записи (manual, broadcast) отключить нельзя — они всегда разрешены.
     *
     * @var array<string, string>
     */
    private const TOGGLEABLE_CONTEXTS = [
        'reminder' => 'sms_enabled_reminder',
        'retention' => 'sms_enabled_retention',
    ];

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

    /**
     * Включена ли отправка SMS для указанного контекста.
     * По умолчанию (флаг не сохранён) — включено. Контексты без переключателя всегда включены.
     */
    public function isEnabledFor(string $context): bool
    {
        $key = self::TOGGLEABLE_CONTEXTS[$context] ?? null;

        if ($key === null) {
            return true;
        }

        return Setting::get($key, '1') !== '0';
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
        if (! $this->isEnabledFor($context)) {
            Log::info('SMS не отправлено: тип уведомления отключён в настройках.', [
                'phone' => $phone,
                'context' => $context,
            ]);

            return false;
        }

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

            $eskizMessageId = $response->json('id') ?? $response->json('data.id') ?? $response->json('message_id');

            $this->record($phone, $message, 'sent', $clientId, $context, is_scalar($eskizMessageId) ? (string) $eskizMessageId : null);

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

    /**
     * Запросить у Eskiz статус доставки сообщения по его id.
     * Возвращает нормализованный статус (delivered | undelivered | rejected | waiting)
     * либо null, если получить не удалось.
     */
    public function fetchStatus(string $messageId): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client()->get('/message/sms/status_by_id/'.$messageId);

            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                $response = $this->client()->get('/message/sms/status_by_id/'.$messageId);
            }

            if (! $response->successful()) {
                return null;
            }

            $raw = $response->json('data.status') ?? $response->json('data.0.status');

            return $this->normalizeDeliveryStatus(is_string($raw) ? $raw : null);
        } catch (\Throwable $e) {
            Log::warning('Не удалось получить статус SMS Eskiz', ['id' => $messageId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Привести SMPP-статус Eskiz к одному из наших значений delivery_status.
     */
    private function normalizeDeliveryStatus(?string $raw): ?string
    {
        $raw = strtoupper(trim((string) $raw));

        if ($raw === '') {
            return null;
        }

        return match (true) {
            in_array($raw, ['DELIVRD', 'DELIVERED'], true) => 'delivered',
            in_array($raw, ['REJECTD', 'REJECTED'], true) => 'rejected',
            in_array($raw, ['UNDELIV', 'UNDELIVERABLE', 'UNDELIVERED', 'EXPIRED', 'DELETED', 'FAILED'], true) => 'undelivered',
            default => 'waiting', // ACCEPTD, ENROUTE, TRANSMTD, waiting и т.п.
        };
    }

    /**
     * Сколько частей (сегментов) займёт SMS: любой многобайтовый символ переводит
     * сообщение в UCS-2 (70/67 символов на часть), иначе GSM-7 (160/153).
     */
    public static function segments(string $message): int
    {
        $length = mb_strlen($message);
        $isUnicode = strlen($message) !== $length;

        if ($isUnicode) {
            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }

    /**
     * Ориентировочный тариф оператора (сум за одну часть) по префиксу номера.
     */
    public static function tariffForPhone(string $phone): int
    {
        /** @var array{default?: int, prefixes?: array<string, int>} $tariffs */
        $tariffs = config('services.eskiz.tariffs', []);
        $prefixes = $tariffs['prefixes'] ?? [];
        $default = (int) ($tariffs['default'] ?? 0);

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $code = strlen($digits) >= 5 ? substr($digits, 3, 2) : '';

        return (int) ($prefixes[$code] ?? $default);
    }

    private function record(string $phone, string $message, string $status, ?int $clientId, string $context, ?string $eskizMessageId = null): void
    {
        SmsMessage::create([
            'client_id' => $clientId,
            'phone' => $phone,
            'message' => $message,
            'status' => $status,
            'context' => $context,
            'eskiz_message_id' => $eskizMessageId,
            'parts' => self::segments($message),
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
