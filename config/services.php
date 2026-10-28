<?php

declare(strict_types=1);

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'exchange_rate' => [
        'driver' => env('EXCHANGE_RATE_DRIVER', 'dolarapi'),
        'url' => env('EXCHANGE_RATE_URL', 'https://ve.dolarapi.com/v1/dolares'),
        'timeout' => (int) env('EXCHANGE_RATE_TIMEOUT', 8),
        'field_priority' => ['promedio', 'venta', 'compra'],
    ],

    'mailtrap' => [
        'api_url' => env('MAILTRAP_API_URL', 'https://mailtrap.io'),
        'api_token' => env('MAILTRAP_API_TOKEN'),
        'inbox_id' => env('MAILTRAP_INBOX_ID'),
        'sending_webhook_secret' => env('MAILTRAP_SENDING_WEBHOOK_SECRET'),
        'inbound_webhook_secret' => env('MAILTRAP_INBOUND_WEBHOOK_SECRET'),
        'timeout' => (int) env('MAILTRAP_TIMEOUT', 15),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
