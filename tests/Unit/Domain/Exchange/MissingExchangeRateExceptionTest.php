<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSource;

/**
 * «No hay ninguna tasa que usar»: un problema de **negocio**, y por eso
 * bloquea (`REQ-FX-005` SP-2, `REQ-FX-006` SP-1).
 *
 * Casi nada exige tasa —navegar, ver una ficha y apartar funcionan sin ella—;
 * lo que no se puede hacer a ciegas es conciliar un pago declarado en
 * bolívares, porque el importe en dólares que salga de ahí se congela para
 * siempre en `payments`.
 */
it('responde 422 y no 503 a la falta de tasa', function (): void {
    expect(MissingExchangeRateException::STATUS)->toBe(422);
});

/**
 * El `422` lleva dentro **qué hacer**. Sin esa frase, la pantalla solo podría
 * decir «no se puede aprobar», que deja a quien lo lee donde estaba.
 */
it('dice cómo desbloquear la operación', function (): void {
    $exception = MissingExchangeRateException::whileSettling(RateSource::Bcv);

    expect($exception->getMessage())
        ->toContain('Ajustes')
        ->toContain('tasa manual')
        ->toContain('bcv');
});

/**
 * La cara contraria de la separación: esto **no** es «la fuente no respondió».
 * Enseñar ese mensaje a quien lo que tiene que hacer es escribir una cifra en
 * Ajustes lo mandaría a esperar a que se arregle algo que no está roto.
 */
it('no puede capturarse por error como fallo de la fuente', function (): void {
    $exception = MissingExchangeRateException::whileSettling(RateSource::Manual);

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception)->not->toBeInstanceOf(ExchangeRateUnavailableException::class);
});
