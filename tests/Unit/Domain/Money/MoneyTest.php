<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use Carbon\CarbonImmutable;

it('declara los ocho métodos del contrato', function (string $method): void {
    expect(method_exists(Money::class, $method))->toBeTrue();
})->with([
    'fromDecimalString',
    'add',
    'subtract',
    'multiply',
    'isNegative',
    'isZeroOrBelowTolerance',
    'allocateEvenly',
    'convertTo',
]);

it('toma USD por defecto, porque la deuda siempre es en USD', function (): void {
    expect((new Money(2_000))->currency)->toBe(Currency::USD);
    expect((new Money(2_000))->cents)->toBe(2_000);
});

it('es inmutable y devuelve instancias nuevas', function (): void {
    expect((new ReflectionClass(Money::class))->isReadOnly())->toBeTrue();

    $original = new Money(2_000);
    $sum = $original->add(new Money(500));

    expect($sum)->not->toBe($original);
    expect($original->cents)->toBe(2_000);
    expect($sum->cents)->toBe(2_500);
});

it('resta importes de la misma moneda', function (): void {
    expect((new Money(2_000))->subtract(new Money(750))->cents)->toBe(1_250);
});

it('permite que una resta dé negativo, que es el crédito', function (): void {
    $credit = (new Money(1_000))->subtract(new Money(1_500));

    expect($credit->cents)->toBe(-500);
    expect($credit->isNegative())->toBeTrue();
    expect((new Money(0))->isNegative())->toBeFalse();
    expect((new Money(1))->isNegative())->toBeFalse();
});

it('multiplica por un número entero de veces', function (): void {
    expect((new Money(1_250))->multiply(3)->cents)->toBe(3_750);
    expect((new Money(1_250))->multiply(0)->cents)->toBe(0);
    expect((new Money(1_250))->multiply(-1)->cents)->toBe(-1_250);
});

it('no admite multiplicar por un decimal', function (): void {
    $factor = (new ReflectionMethod(Money::class, 'multiply'))->getParameters()[0];

    expect((string) $factor->getType())->toBe('int');
});

it('conserva la moneda en toda operación', function (): void {
    $ves = new Money(85_000, Currency::VES);

    expect($ves->add(new Money(1, Currency::VES))->currency)->toBe(Currency::VES);
    expect($ves->subtract(new Money(1, Currency::VES))->currency)->toBe(Currency::VES);
    expect($ves->multiply(2)->currency)->toBe(Currency::VES);
});

it('lanza al operar entre monedas distintas', function (string $operation): void {
    $usd = new Money(2_000, Currency::USD);
    $ves = new Money(85_000, Currency::VES);

    expect(fn (): Money => $usd->{$operation}($ves))->toThrow(CurrencyMismatchException::class);
})->with(['add', 'subtract']);

it('nombra las dos monedas y la operación que chocó', function (): void {
    $usd = new Money(2_000, Currency::USD);
    $ves = new Money(85_000, Currency::VES);

    expect(fn (): Money => $usd->add($ves))
        ->toThrow(CurrencyMismatchException::class, 'No se puede operar (sumar) entre USD y VES');
});

it('da por saldado lo que cabe en la tolerancia', function (int $cents, int $tolerance, bool $settled): void {
    expect((new Money($cents))->isZeroOrBelowTolerance($tolerance))->toBe($settled);
})->with([
    'saldado exacto' => [0, 50, true],
    'dentro de la tolerancia' => [30, 50, true],
    'justo en el borde' => [50, 50, true],
    'un céntimo por encima' => [51, 50, false],
    'sin tolerancia y con deuda' => [1, 0, false],
    'sin tolerancia y a cero' => [0, 0, true],
    'pagó de más: también cierra' => [-500, 50, true],
]);

it('no deja ningún método sin implementar', function (callable $call): void {
    expect($call())->not->toBeNull();
})->with([
    'el reparto' => [fn (): array => (new Money(10_000))->allocateEvenly(3)],
    'el parseo' => [fn (): Money => Money::fromDecimalString('19.99')],
    'la conversión' => [fn (): Money => (new Money(2_000))->convertTo(
        Currency::VES,
        new RateSnapshot('42.50000000', RateSource::Bcv, CarbonImmutable::now(), CarbonImmutable::now()),
    )],
    'el formateo' => [fn (): string => MoneyFormatter::format(new Money(2_000))],
]);

it('responde con el valor que le toca a cada uno', function (): void {
    $rate = new RateSnapshot('42.50000000', RateSource::Bcv, CarbonImmutable::now(), CarbonImmutable::now());

    expect((new Money(10_000))->allocateEvenly(3))->toHaveCount(3);
    expect(Money::fromDecimalString('19.99')->cents)->toBe(1_999);
    expect((new Money(2_000))->convertTo(Currency::VES, $rate)->cents)->toBe(85_000);
    expect(MoneyFormatter::format(new Money(2_000)))->toBe('$20,00');
});

it('exige la tasa con su procedencia, no un número suelto', function (): void {
    $parameters = (new ReflectionMethod(Money::class, 'convertTo'))->getParameters();

    expect((string) $parameters[0]->getType())->toBe(Currency::class);
    expect((string) $parameters[1]->getType())->toBe(RateSnapshot::class);
});

it('no admite ni devuelve coma flotante en ninguna firma', function (): void {
    foreach ((new ReflectionClass(Money::class))->getMethods() as $method) {
        expect((string) $method->getReturnType())->not->toContain('float');

        foreach ($method->getParameters() as $parameter) {
            expect((string) $parameter->getType())->not->toContain('float');
        }
    }

    expect((string) (new ReflectionProperty(Money::class, 'cents'))->getType())->toBe('int');
});
