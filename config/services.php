<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'eskiz' => [
        'email' => env('ESKIZ_EMAIL'),
        'password' => env('ESKIZ_PASSWORD'),
        'base_url' => env('ESKIZ_BASE_URL', 'https://notify.eskiz.uz/api'),
        'from' => env('ESKIZ_FROM', '4546'),

        // Ориентировочные тарифы (сум за одну часть SMS) для оценки расходов.
        // prefixes — по 2-значному коду оператора после 998; default — для прочих.
        'tariffs' => [
            'default' => 130,
            'prefixes' => [
                '90' => 115, '91' => 115, // Beeline
                '93' => 160, '94' => 160, // Ucell
                '88' => 95,               // Humans
                '97' => 110, '95' => 110, // Mobiuz / UMS
                '99' => 145, '33' => 145, // Uzmobile
                '98' => 95,               // Perfectum
            ],
        ],
    ],

    'barbershop' => [
        'retention_days' => (int) env('RETENTION_DAYS', 14),
    ],

];
