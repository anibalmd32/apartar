<?php

declare(strict_types=1);

use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\RateDeviation;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Money\Currency;
use Carbon\CarbonImmutable;

it('muestra las dos tasas, cuánto se aleja y hacia dónde', function (): void {
    $reference = new RateSnapshot('42.50', RateSource::Bcv, CarbonImmutable::now(), CarbonImmutable::now());
    $deviation = RateDeviation::in(new ManualRate('4.25'), $reference, Currency::VES) ?? throw new RuntimeException('Debió desviarse.');

    expect((new UnconfirmedRateDeviationException($deviation))->getMessage())
        ->toContain('(4,25)')
        ->toContain('90 % por debajo')
        ->toContain('(42,50)');
});

it('se responde con 422', function (): void {
    expect(UnconfirmedRateDeviationException::STATUS)->toBe(422);
});
