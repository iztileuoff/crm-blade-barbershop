<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-retention-messages {--dry-run : Показать получателей без реальной отправки} {--phone= : Отправить тестовое SMS на один номер (998XXXXXXXXX)} {--backlog : Разовый догоняющий прогон — все, кто не был N и более дней (по умолчанию только ровно N дней назад)}')]
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
        $backlog = (bool) $this->option('backlog');
        $days = (int) config('services.barbershop.retention_days', 14);

        // Повторно напоминаем не чаще, чем раз в $days дней (тумблер last_retention_sent_at).
        $notMessagedSince = Carbon::now()->subDays($days)->startOfDay();

        $query = Client::query()
            ->whereNotNull('last_visit_at')
            ->where(function ($q) use ($notMessagedSince) {
                $q->whereNull('last_retention_sent_at')
                    ->orWhere('last_retention_sent_at', '<', $notMessagedSince);
            });

        if ($backlog) {
            // Разовый догоняющий прогон: все, кто не был $days и более дней.
            $query->where('last_visit_at', '<=', Carbon::now()->subDays($days)->endOfDay());
        } else {
            // Ежедневная когорта: ровно $days дней назад.
            $query->whereBetween('last_visit_at', [
                Carbon::now()->subDays($days)->startOfDay(),
                Carbon::now()->subDays($days)->endOfDay(),
            ]);
        }

        $clients = $query->get();

        if ($dryRun) {
            $window = $backlog ? "{$days}+ дн. назад (backlog)" : "ровно {$days} дн. назад";
            $this->info('Пробный запуск (--dry-run): SMS НЕ отправляются.');
            $this->info("Клиентов под условие ({$window}): {$clients->count()}");
            // Текст — по клиенту, а не один на прогон: с тех пор как рассылка
            // говорит на языке клиента, список без текстов не показывает, что
            // именно уедет, и опечатка в uz/kaa-шаблоне уходит незамеченной.
            foreach ($clients as $client) {
                $locale = NotificationTemplates::localeFor($client->locale);

                $this->line("  • {$client->phone} (последний визит: {$client->last_visit_at?->toDateString()}, язык: {$locale})");
                $this->line('    '.NotificationTemplates::renderSms('retention', [], $client->locale));
            }

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            $message = NotificationTemplates::renderSms('retention', [], $client->locale);

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

        // Язык клиента, если этот номер в базе: иначе живая проверка доставки
        // подтверждала бы текст на языке салона, который на этот номер
        // рассылка никогда не отправит.
        $client = Client::where('phone', $normalized)->first();
        $locale = NotificationTemplates::localeFor($client?->locale);

        $message = NotificationTemplates::renderSms('retention', [], $client?->locale);
        $this->info("Тестовая отправка на {$normalized} (язык: {$locale}): {$message}");

        if ($sms->sendSms($normalized, $message, null, 'retention')) {
            $this->info('Отправлено успешно.');

            return self::SUCCESS;
        }

        $this->error('Не отправлено. Проверьте учётные данные Eskiz и тумблер sms_enabled_retention.');

        return self::FAILURE;
    }
}
