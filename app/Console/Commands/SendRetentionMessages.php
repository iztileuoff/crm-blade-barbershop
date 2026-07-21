<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-retention-messages {--dry-run : Показать получателей без реальной отправки} {--phone= : Отправить тестовое SMS на один номер (998XXXXXXXXX)}')]
#[Description('SMS-удержание: отправка клиентам, чей последний визит был N дней назад')]
class SendRetentionMessages extends Command
{
    public function handle(SmsService $sms): int
    {
        $testPhone = $this->option('phone');
        if (is_string($testPhone) && $testPhone !== '') {
            return $this->sendTest($sms, $testPhone);
        }

        $dryRun = (bool) $this->option('dry-run');
        $days = (int) config('services.barbershop.retention_days', 14);
        $target = Carbon::now()->subDays($days)->startOfDay();
        $end = Carbon::now()->subDays($days)->endOfDay();

        $clients = Client::query()
            ->whereBetween('last_visit_at', [$target, $end])
            ->where(function ($q) use ($target) {
                $q->whereNull('last_retention_sent_at')
                    ->orWhere('last_retention_sent_at', '<', $target);
            })
            ->get();

        $message = NotificationTemplates::renderSms('retention');

        if ($dryRun) {
            $this->info('Пробный запуск (--dry-run): SMS НЕ отправляются.');
            $this->info("Клиентов под условие ({$days} дн. назад): {$clients->count()}");
            foreach ($clients as $client) {
                $this->line("  • {$client->phone} (последний визит: {$client->last_visit_at?->toDateString()})");
            }
            $this->info("Текст: {$message}");

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            if ($sms->sendSms($client->phone, $message, $client->id, 'retention')) {
                $client->forceFill(['last_retention_sent_at' => Carbon::now()])->save();
                $this->info("SMS-удержание отправлено клиенту {$client->phone}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Send a single retention SMS to one number for a live delivery check.
     * Does not touch any client record.
     */
    private function sendTest(SmsService $sms, string $phone): int
    {
        $normalized = Client::normalizePhone($phone);
        if ($normalized === null) {
            $this->error("Некорректный номер: {$phone}. Ожидается формат 998XXXXXXXXX.");

            return self::FAILURE;
        }

        $message = NotificationTemplates::renderSms('retention');
        $this->info("Тестовая отправка на {$normalized}: {$message}");

        if ($sms->sendSms($normalized, $message, null, 'retention')) {
            $this->info('Отправлено успешно.');

            return self::SUCCESS;
        }

        $this->error('Не отправлено. Проверьте учётные данные Eskiz и тумблер sms_enabled_retention.');

        return self::FAILURE;
    }
}
