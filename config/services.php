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

    /*
    |--------------------------------------------------------------------------
    | Tasa de cambio (REQ-FX-002, REQ-FX-003, [05 §6])
    |--------------------------------------------------------------------------
    |
    | `driver` elige la implementación de `ExchangeRateProvider` que resuelve
    | las fuentes públicas; lo resuelve `ExchangeServiceProvider` y un nombre
    | que no existe **falla al arrancar**, no en tiempo de request
    | (`REQ-FX-003` SP-5).
    |
    | `timeout` son 8 s (`REQ-FX-002` SP-3): la ingesta corre en la cola `high`
    | y una petición colgada bloquearía al worker detrás de un servicio que no
    | controlamos.
    |
    | `field_priority` es el orden en que se busca la cifra dentro de cada
    | cotización ([05 §6.2]): el **primer valor > 0**. Está en configuración y
    | no en el código por si el negocio decide después que quiere `venta` en
    | lugar de `promedio`.
    |
    */
    'exchange_rate' => [
        'driver' => env('EXCHANGE_RATE_DRIVER', 'dolarapi'),
        'url' => env('EXCHANGE_RATE_URL', 'https://ve.dolarapi.com/v1/dolares'),
        'timeout' => (int) env('EXCHANGE_RATE_TIMEOUT', 8),
        'field_priority' => ['promedio', 'venta', 'compra'],
    ],

];
