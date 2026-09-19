<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSource;

/**
 * «La fuente no respondió»: un problema de **ingesta**, y por eso reintentable
 * (`REQ-FX-002` SP-1, SP-3, SP-4).
 *
 * Quien la recoge es el job `FetchExchangeRate` (`T-020`), que reintenta con
 * backoff y conserva la caché y el histórico. Nunca llega al cliente: el
 * catálogo sigue proyectando con la última tasa persistida y nadie deja de
 * vender por esto (`REQ-FX-005` RB-1).
 */
it('nombra la fuente que falló, para que el log diga cuál', function (): void {
    $exception = ExchangeRateUnavailableException::unreachable(RateSource::Parallel, 'timeout de 8 s');

    expect($exception->getMessage())
        ->toContain('parallel')
        ->toContain('timeout de 8 s')
        ->toContain('última tasa persistida');
});

/**
 * SP-4: un cambio de formato del proveedor sigue igual de roto en el tercer
 * reintento, así que el mensaje tiene que mandar a mirar el parser en lugar de
 * sugerir que se espere.
 */
it('distingue el cambio de formato del fallo de red', function (): void {
    $exception = ExchangeRateUnavailableException::unexpectedPayload(RateSource::Bcv, 'promedio|venta|compra');

    expect($exception->getMessage())
        ->toContain('bcv')
        ->toContain('promedio|venta|compra')
        ->toContain('parser');
});

/**
 * **No comparte jerarquía con `MissingExchangeRateException`**, y ese es el
 * motivo entero de que sean dos tipos: capturar una no puede capturar la otra
 * por descuido. Con un solo tipo, el job reintentaría un fallo que reintentar
 * no arregla —no hay tasa: leerla mil veces no la crea—.
 */
it('no puede capturarse por error como falta de tasa', function (): void {
    $exception = ExchangeRateUnavailableException::unreachable(RateSource::Bcv, 'sin respuesta');

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception)->not->toBeInstanceOf(MissingExchangeRateException::class);
});
