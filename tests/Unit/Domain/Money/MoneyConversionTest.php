<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

function rateOf(string $rate, RateSource $source = RateSource::Bcv): RateSnapshot
{
    return new RateSnapshot($rate, $source, CarbonImmutable::now(), CarbonImmutable::now());
}

it('convierte de dólares a bolívares', function (): void {
    $converted = (new Money(2_000))->convertTo(Currency::VES, rateOf('42.50000000'));

    expect($converted->cents)->toBe(85_000);
    expect($converted->currency)->toBe(Currency::VES);
});

it('convierte de bolívares a dólares', function (): void {
    $converted = (new Money(85_000, Currency::VES))->convertTo(Currency::USD, rateOf('42.50000000'));

    expect($converted->cents)->toBe(2_000);
    expect($converted->currency)->toBe(Currency::USD);
});

it('conserva el importe al convertir a la misma moneda', function (): void {
    $original = new Money(1_999);
    $converted = $original->convertTo(Currency::USD, rateOf('42.50000000'));

    expect($converted->cents)->toBe(1_999);
    expect($converted)->not->toBe($original);
});

it('no mira la tasa cuando no hay nada que convertir', function (string $rate): void {
    expect((new Money(1_999))->convertTo(Currency::USD, rateOf($rate))->cents)->toBe(1_999);
})->with(['0', '-1', 'cuarenta y dos', '']);

it('redondea half-up al céntimo, también en negativo', function (int $cents, string $rate, int $expected): void {
    expect((new Money($cents))->convertTo(Currency::VES, rateOf($rate))->cents)->toBe($expected);
})->with([
    'hacia abajo' => [1, '1.4', 1],
    'la mitad justa sube' => [1, '1.5', 2],
    'hacia arriba' => [1, '1.6', 2],
    'mitad justa en negativo, se aleja del cero' => [-1, '1.5', -2],
    'hacia abajo en negativo' => [-1, '1.4', -1],
    'tasa con los ocho decimales' => [100, '42.12345678', 4_212],
    'tasa que no llega al céntimo' => [1, '0.00000001', 0],
]);

it('cuadra el cronograma convertido con el total convertido', function (string $rate): void {
    $total = new Money(123_457);

    $convertedTotal = $total->convertTo(Currency::VES, rateOf($rate))->cents;

    $sumOfConverted = array_sum(array_map(
        fn (Money $installment): int => $installment->convertTo(Currency::VES, rateOf($rate))->cents,
        $total->allocateEvenly(40),
    ));

    expect($sumOfConverted)->toBe(
        $convertedTotal,
        sprintf(
            'A la tasa %s, la suma de las cuarenta cuotas convertidas (%d) no cuadra con el total convertido '
            .'(%d): se está redondeando en pasos intermedios.',
            $rate,
            $sumOfConverted,
            $convertedTotal,
        ),
    );
})->with([
    'tasa corriente' => ['42.50000000'],
    'tasa con ocho decimales' => ['36.12345678'],
    'tasa entera' => ['40.00000000'],
    'tasa pequeña' => ['1.00000001'],
    'tasa grande' => ['9999.99999999'],
]);

it('vuelve al importe de partida en el viaje de ida y vuelta', function (string $rate): void {
    $original = new Money(2_000);

    $roundTrip = $original
        ->convertTo(Currency::VES, rateOf($rate))
        ->convertTo(Currency::USD, rateOf($rate));

    expect($roundTrip->cents)->toBe($original->cents);
})->with(['42.50000000', '36.12000000', '1.00000000', '250.00000000']);

it('lanza antes de convertir con una tasa que no es positiva', function (string $rate): void {
    expect(fn (): Money => (new Money(2_000))->convertTo(Currency::VES, rateOf($rate)))
        ->toThrow(InvalidRateException::class);
})->with(['0', '0.00000000', '-1', '-42.50000000', '0.0']);

it('nombra la tasa que rechazó', function (): void {
    expect(fn (): Money => (new Money(2_000))->convertTo(Currency::VES, rateOf('0')))
        ->toThrow(InvalidRateException::class, 'no es mayor que cero');
});

it('rechaza una tasa que bcmath no sabría leer', function (string $rate): void {
    expect(fn (): Money => (new Money(2_000))->convertTo(Currency::VES, rateOf($rate)))
        ->toThrow(InvalidRateException::class, 'no es un decimal válido');
})->with([
    'palabras' => ['cuarenta y dos'],
    'vacía' => [''],
    'exponencial, que bcmul rechaza' => ['4.25e1'],
    'con separador de millar' => ['1,234.56'],
    'con espacios' => [' 42.50 '],
    'solo el signo' => ['-'],
]);

it('usa los ocho decimales de la tasa sin pasar por float', function (): void {
    $converted = (new Money(100_000_000))->convertTo(Currency::VES, rateOf('42.12345678'));

    expect($converted->cents)->toBe(4_212_345_678);
});

it('convierte un crédito conservando el signo', function (): void {
    expect((new Money(-2_000))->convertTo(Currency::VES, rateOf('42.50000000'))->cents)->toBe(-85_000);
});

it('convierte cero a cero', function (): void {
    expect((new Money(0))->convertTo(Currency::VES, rateOf('42.50000000'))->cents)->toBe(0);
});
