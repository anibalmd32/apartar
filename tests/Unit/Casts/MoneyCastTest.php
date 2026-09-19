<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Models\Plan;

/**
 * La frontera entre el `BIGINT` de la fila y el *value object* (`REQ-FX-001`).
 *
 * **El cast no está cableado a ninguna columna todavía** —eso es de cada tarea
 * que estrene un modelo con importes—, así que se ejercita llamándolo
 * directamente con un modelo cualquiera. `Plan` sirve de portador: lo único
 * que el cast le pide es su tabla, para poder nombrar la columna en los
 * mensajes.
 */
function moneyCast(): MoneyCast
{
    return new MoneyCast;
}

/**
 * SP-6, y es la aserción que da nombre a la tarea: `null` es `null`.
 *
 * «Sin precio de comparación» y «precio de comparación de cero dólares» son
 * cosas distintas, y la segunda se pinta en la vitrina como un descuento del
 * 100 %.
 */
it('devuelve null y no Money(0) para una columna vacía', function (): void {
    $value = moneyCast()->get(new Plan, 'compare_at_price_cents', null, []);

    expect($value)->toBeNull();
    expect($value)->not->toBeInstanceOf(Money::class);
});

it('deja null al persistir una columna opcional vacía', function (): void {
    expect(moneyCast()->set(new Plan, 'compare_at_price_cents', null, []))->toBeNull();
});

/**
 * MySQL devuelve un `BIGINT` como cadena: si el cast no lo convirtiera a
 * entero, `Money::cents` dejaría de ser `int` y toda la aritmética entera se
 * apoyaría en la conversión implícita de PHP.
 */
it('hidrata la cadena del driver como importe entero en dólares', function (mixed $stored): void {
    $money = moneyCast()->get(new Plan, 'price_cents', $stored, []);

    expect($money)->toBeInstanceOf(Money::class);
    expect($money?->cents)->toBe(2_000);
    expect($money?->cents)->toBeInt();
    expect($money?->currency)->toBe(Currency::USD);
})->with([
    'como cadena, que es lo que devuelve MySQL' => ['2000'],
    'como entero' => [2_000],
]);

it('hidrata el negativo, que es el crédito de SP-3', function (): void {
    expect(moneyCast()->get(new Plan, 'price_cents', '-500', [])?->cents)->toBe(-500);
});

it('persiste un Money como sus céntimos', function (): void {
    expect(moneyCast()->set(new Plan, 'price_cents', new Money(2_000), []))->toBe(2_000);
});

/**
 * Las factories y las migraciones de datos escriben el entero pelado, y eso
 * tiene que seguir funcionando: obligarlas a envolverlo sería fricción sin
 * ganancia, porque un `int` ya es exactamente lo que la columna guarda.
 */
it('admite también el entero pelado que escriben las factories', function (): void {
    expect(moneyCast()->set(new Plan, 'price_cents', 2_000, []))->toBe(2_000);
});

/**
 * RB-1 en el punto por el que la coma flotante entraría sin que nadie la
 * viera. `19.99` como `float` ya no vale 19,99, y aceptarlo aquí dejaría que
 * el error de redondeo se persistiera.
 */
it('rechaza un float en los dos sentidos', function (): void {
    expect(fn (): ?int => moneyCast()->set(new Plan, 'price_cents', 19.99, []))
        ->toThrow(InvalidArgumentException::class, 'Money o un entero de céntimos');

    expect(fn (): ?Money => moneyCast()->get(new Plan, 'price_cents', 19.99, []))
        ->toThrow(InvalidArgumentException::class, 'float');
});

it('rechaza una cadena decimal y manda a convertirla antes', function (): void {
    expect(fn (): ?int => moneyCast()->set(new Plan, 'price_cents', '19.99', []))
        ->toThrow(InvalidArgumentException::class, 'Money::fromDecimalString()');
});

/**
 * Toda columna `*_cents` es de centavos de **USD**. Guardar ahí bolívares
 * produce una fila indistinguible de una correcta —el número cabe y la columna
 * lo acepta—, y el error solo aparece cuando alguien suma ese saldo con otro.
 */
it('lanza al intentar persistir un importe que no es en USD', function (): void {
    expect(fn (): ?int => moneyCast()->set(new Plan, 'price_cents', new Money(85_000, Currency::VES), []))
        ->toThrow(CurrencyMismatchException::class, 'plans.price_cents');
});

/**
 * El cast **no** recibe la moneda por parámetro, y es deliberado: una moneda
 * configurable invitaría a declarar una columna en bolívares desde el
 * `$casts` y a romper la invariante de RB-1 desde el sitio más discreto.
 */
it('no admite configurar la moneda de la columna', function (): void {
    expect((new ReflectionClass(MoneyCast::class))->getConstructor())->toBeNull();
});
