<?php

namespace App\Providers;

use App\Services\SmsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsService::class, function ($app) {
            $config = $app['config']->get('services.eskiz');

            return new SmsService(
                baseUrl: $config['base_url'],
                email: (string) $config['email'],
                password: (string) $config['password'],
                from: (string) $config['from'],
            );
        });
    }

    public function boot(): void
    {
        Carbon::setLocale('ru');
    }
}
