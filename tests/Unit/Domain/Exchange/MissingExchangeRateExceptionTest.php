<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSource;

it('responde 422 y no 503 a la falta de tasa', function (): void {
    expect(MissingExchangeRateException::STATUS)->toBe(422);
});

it('dice cómo desbloquear la operación', function (): void {
    $exception = MissingExchangeRateException::whileSettling(RateSource::Bcv);

    expect($exception->getMessage())
        ->toContain('Ajustes')
        ->toContain('tasa manual')
        ->toContain('bcv');
});

it('no puede capturarse por error como fallo de la fuente', function (): void {
    $exception = MissingExchangeRateException::whileSettling(RateSource::Manual);

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception)->not->toBeInstanceOf(ExchangeRateUnavailableException::class);
});
