<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use Carbon\CarbonImmutable;

/**
 * El tipo del que depende todo el dinero del sistema (`REQ-FX-001`).
 *
 * Aquí viven **la forma** —las ocho firmas del bloque normativo— y **la
 * aritmética entera**: sumar, restar, multiplicar por un entero, comparar con
 * la tolerancia. Es la parte exacta por construcción.
 *
 * Los cuatro sitios donde vive el riesgo tienen su propio archivo, porque cada
 * uno necesita una batería entera: `MoneyAllocationTest` (los 500 casos de la
 * invariante), `MoneyConversionTest` (`bcmul` y el redondeo único),
 * `MoneyParsingTest` (la frontera de lo que se acepta) y `MoneyFormatterTest`
 * (los dos formatos exactos).
 */

/**
 * Las ocho firmas del bloque normativo, con esos nombres y esos tipos. Un
 * método que se renombre «mejorándolo» rompe el contrato compartido del
 * módulo, que es lo que `LAY`, `PAY` y `SUB` van a consumir.
 */
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

/**
 * RB-2. Un `Money` mutable convertiría cualquier referencia compartida —el
 * total de un apartado pasado a dos colaboradores— en errores que no se
 * reproducen.
 */
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

/**
 * SP-3: el negativo **está permitido**, porque existe el crédito de quien pagó
 * de más. Quien no lo admita lo rechaza en su propia regla; el tipo no puede
 * distinguir un saldo a favor legítimo de una resta equivocada.
 */
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

/**
 * El factor es `int` y no `float`: multiplicar dinero por un decimal es
 * aplicar un porcentaje, y eso está fuera del alcance de `REQ-FX-001`.
 */
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

/**
 * RB-3: mezclar monedas lanza. La alternativa —sumar los enteros— devuelve una
 * cifra plausible y falsa, que es la peor de las dos formas de estar mal.
 */
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

/**
 * La tolerancia de liquidación (`settlement_tolerance_cents`). Lo que se fija
 * aquí es que es `<=` y **no** `abs() <=`: quien pagó de más también cierra su
 * apartado, porque un pago de sobra no es una razón para dejarlo abierto.
 */
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

/**
 * **`T-017` dejó cuatro métodos lanzando un `LogicException` que nombraba a
 * esta tarea, y no queda ninguno.**
 *
 * Esta comprobación es la que impide que uno vuelva. Un muñón reintroducido
 * —por una fusión mal resuelta, por un `git revert` de más— no rompería la
 * compilación ni ningún tipo: rompería el cronograma de un apartado en
 * producción, que es donde se descubren estas cosas. Su comportamiento real
 * lo prueban `MoneyAllocationTest`, `MoneyConversionTest`, `MoneyParsingTest`
 * y `MoneyFormatterTest`; lo que se fija aquí es que **responden**.
 */
it('no deja ningún método sin implementar', function (callable $call): void {
    // Un muñón lanzaría, y el propio `LogicException` haría fallar el test con
    // su mensaje delante —que es el que dice qué falta—. Que la llamada
    // devuelva algo es la comprobación entera.
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
    expect(MoneyFormatter::format(new Money(2_000)))->toBe('$20.00');
});

/**
 * `convertTo()` recibe un `RateSnapshot` y no un número suelto, que es lo que
 * obliga a que toda conversión lleve su procedencia y su fecha —lo que
 * `REQ-FX-006` congela en `payments`—. Es también la dependencia que hizo que
 * `T-019` se adelantara a esta tarea.
 */
it('exige la tasa con su procedencia, no un número suelto', function (): void {
    $parameters = (new ReflectionMethod(Money::class, 'convertTo'))->getParameters();

    expect((string) $parameters[0]->getType())->toBe(Currency::class);
    expect((string) $parameters[1]->getType())->toBe(RateSnapshot::class);
});

/**
 * RB-1 en el código, no solo en el esquema: ni un `float` en la firma de
 * ninguna operación. Si entrara por aquí, todo lo demás daría igual.
 */
it('no admite ni devuelve coma flotante en ninguna firma', function (): void {
    foreach ((new ReflectionClass(Money::class))->getMethods() as $method) {
        expect((string) $method->getReturnType())->not->toContain('float');

        foreach ($method->getParameters() as $parameter) {
            expect((string) $parameter->getType())->not->toContain('float');
        }
    }

    expect((string) (new ReflectionProperty(Money::class, 'cents'))->getType())->toBe('int');
});
