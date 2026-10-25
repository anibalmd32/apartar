<?php

declare(strict_types=1);

return [
    'admin' => [
        'name' => env('PLATFORM_ADMIN_NAME'),
        'email' => env('PLATFORM_ADMIN_EMAIL'),
        'password' => env('PLATFORM_ADMIN_PASSWORD'),
    ],

    'billing_accounts' => [
        ['method' => 'Pago móvil · '.env('PLATFORM_PAGO_MOVIL_BANK', ''), 'detail' => env('PLATFORM_PAGO_MOVIL_DETAIL')],
        ['method' => 'Zelle', 'detail' => env('PLATFORM_ZELLE_EMAIL')],
        ['method' => 'Binance Pay', 'detail' => env('PLATFORM_BINANCE_PAY_ID')],
    ],
];
