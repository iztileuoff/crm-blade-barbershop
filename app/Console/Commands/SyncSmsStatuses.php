<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Services\SmsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:sync-sms-statuses')]
#[Description('Опрашивает Eskiz и обновляет статус доставки отправленных SMS (delivered/undelivered/rejected)')]
class SyncSmsStatuses extends Command
{
    public function handle(SmsService $sms): int
    {
        if (! $sms->isConfigured()) {
            $this->warn('Eskiz не настроен — пропуск.');

            return self::SUCCESS;
        }

        $messages = SmsMessage::query()
            ->where('status', 'sent')
            ->whereNotNull('eskiz_message_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                    ->orWhereNotIn('delivery_status', SmsMessage::FINAL_DELIVERY_STATUSES);
            })
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->limit(500)
            ->get();

        foreach ($messages as $message) {
            $status = $sms->fetchStatus((string) $message->eskiz_message_id);

            if ($status === null) {
                continue;
            }

            $message->forceFill([
                'delivery_status' => $status,
                'status_checked_at' => Carbon::now(),
            ])->save();
        }

        $this->info("Обновлено статусов: {$messages->count()}");

        return self::SUCCESS;
    }
}
