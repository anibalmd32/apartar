<?php

declare(strict_types=1);

use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\RateDeviation;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Money\Currency;
use Carbon\CarbonImmutable;

function bcvAt(string $rate): RateSnapshot
{
    return new RateSnapshot($rate, RateSource::Bcv, CarbonImmutable::now(), CarbonImmutable::now());
}

it('deja pasar una tasa manual dentro del 10 %', function (string $manual): void {
    expect(RateDeviation::in(new ManualRate($manual), bcvAt('42.50'), Currency::VES))->toBeNull();
})->with(['42.50', '46.75', '38.25']);

it('pide confirmar una tasa manual que se aleja más del 10 %', function (): void {
    $deviation = RateDeviation::in(new ManualRate('4.25'), bcvAt('42.50'), Currency::VES);

    expect($deviation)->not->toBeNull()
        ->and($deviation?->percent)->toBe(90)
        ->and($deviation?->isAbove())->toBeFalse()
        ->and($deviation?->toArray())->toMatchArray(['manual' => '4,25', 'reference' => '42,50']);

    expect(RateDeviation::in(new ManualRate('46.76'), bcvAt('42.50'), Currency::VES)?->isAbove())->toBeTrue();
});

it('no aplica a pagos en dólares, a la tasa del día ni sin tasa de referencia', function (): void {
    expect(RateDeviation::in(new ManualRate('4.25'), bcvAt('42.50'), Currency::USD))->toBeNull()
        ->and(RateDeviation::in(bcvAt('4.25'), bcvAt('42.50'), Currency::VES))->toBeNull()
        ->and(RateDeviation::in(new ManualRate('4.25'), null, Currency::VES))->toBeNull();
});

it('solo la confirma el token de esa misma tasa', function (): void {
    $deviation = RateDeviation::in(new ManualRate('4.25'), bcvAt('42.50'), Currency::VES);

    $deviation?->assertConfirmedBy('4.25@42.50');

    expect(fn () => $deviation?->assertConfirmedBy('4.30@42.50'))->toThrow(UnconfirmedRateDeviationException::class);
});
