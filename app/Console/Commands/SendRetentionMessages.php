<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\SmsService;
use App\Support\NotificationTemplates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('app:send-retention-messages')]
#[Description('SMS-удержание: отправка клиентам, чей последний визит был N дней назад')]
class SendRetentionMessages extends Command
{
    public function handle(SmsService $sms): int
    {
        $days = (int) config('services.barbershop.retention_days', 21);
        $target = Carbon::now()->subDays($days)->startOfDay();
        $end = Carbon::now()->subDays($days)->endOfDay();

        $clients = Client::query()
            ->whereBetween('last_visit_at', [$target, $end])
            ->where(function ($q) use ($target) {
                $q->whereNull('last_retention_sent_at')
                    ->orWhere('last_retention_sent_at', '<', $target);
            })
            ->get();

        $message = NotificationTemplates::render('sms_retention', ['days' => $days]);

        foreach ($clients as $client) {
            if ($sms->sendSms($client->phone, $message, $client->id, 'retention')) {
                $client->forceFill(['last_retention_sent_at' => Carbon::now()])->save();
                $this->info("SMS-удержание отправлено клиенту {$client->phone}");
            }
        }

        return self::SUCCESS;
    }
}
