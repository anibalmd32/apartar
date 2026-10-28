<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('behind Traefik genera enlaces https y ve la IP real del visitante', function (): void {
    Route::get('/__proxy-probe', fn () => response()->json([
        'url' => url('/precios'),
        'secure' => request()->isSecure(),
        'ip' => request()->ip(),
    ]));

    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'apartar.app',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-For' => '190.202.10.5',
        ])
        ->getJson('/__proxy-probe')
        ->assertOk()
        ->assertJson(['url' => 'https://apartar.app/precios', 'secure' => true, 'ip' => '190.202.10.5']);
});
