<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSource;

it('nombra la fuente que falló, para que el log diga cuál', function (): void {
    $exception = ExchangeRateUnavailableException::unreachable(RateSource::Parallel, 'timeout de 8 s');

    expect($exception->getMessage())
        ->toContain('parallel')
        ->toContain('timeout de 8 s')
        ->toContain('última tasa persistida');
});

it('distingue el cambio de formato del fallo de red', function (): void {
    $exception = ExchangeRateUnavailableException::unexpectedPayload(RateSource::Bcv, 'promedio|venta|compra');

    expect($exception->getMessage())
        ->toContain('bcv')
        ->toContain('promedio|venta|compra')
        ->toContain('parser');
});

it('no puede capturarse por error como falta de tasa', function (): void {
    $exception = ExchangeRateUnavailableException::unreachable(RateSource::Bcv, 'sin respuesta');

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception)->not->toBeInstanceOf(MissingExchangeRateException::class);
});
