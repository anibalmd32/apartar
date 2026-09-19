<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/**
 * La invariante de `REQ-FX-001` RB-4: `sum(allocateEvenly(n)) === cents`, para
 * **cualquier** `cents` y **cualquier** `n >= 1`.
 *
 * Es la propiedad de la que cuelga el cronograma entero de un apartado. Si
 * falla, el síntoma no es un test en rojo dentro de tres hitos: es un cliente
 * que termina de pagar sus doce cuotas y sigue debiendo un céntimo, en un
 * apartado que nadie sabe reproducir.
 *
 * **Por qué 500 casos y no una tabla escrita a mano.** Un reparto se prueba con
 * `$100 / 4` y sale bien siempre. Los que fallan son los feos —importes
 * negativos, `n` mayor que `cents`, primos, `n = 1`— y no se le ocurren a nadie
 * escribiendo ejemplos. Los casos con nombre de más abajo siguen estando,
 * porque fijan **qué** tiene que salir; la propiedad fija que no haya ningún
 * hueco entre ellos.
 */

/**
 * La semilla es fija a propósito.
 *
 * Un fallo aleatorio que no se puede volver a ejecutar es un fallo que se
 * cierra como «flaky» y se queda. Con la semilla fija, el caso 387 es el caso
 * 387 en la máquina de quien lo arregle, y el mensaje de la aserción lleva
 * además los números concretos.
 */
const ALLOCATION_SEED = 20_260_918;

/**
 * Los rangos que cubre el generador, y el porqué de cada uno.
 *
 * @return list<array{0: int, 1: int}>
 */
function allocationCases(): array
{
    mt_srand(ALLOCATION_SEED);

    $cases = [];

    // Precios de catálogo con pocas cuotas: el caso corriente, el que ya
    // funciona, y que está aquí para que la propiedad no se pruebe solo sobre
    // lo raro.
    for ($i = 0; $i < 150; $i++) {
        $cases[] = [mt_rand(1, 1_000_000), mt_rand(1, 24)];
    }

    // Importes que no dividen exacto casi nunca: primos y casi primos contra
    // números de partes que no los dividen. Es donde vive el residuo.
    foreach ([7, 13, 97, 101, 997, 10_007, 99_991, 1_000_003] as $prime) {
        for ($i = 0; $i < 10; $i++) {
            $cases[] = [$prime, mt_rand(2, 60)];
        }
    }

    // `n` mayor que `cents`: cada parte se lleva 0 y la última se lleva todo.
    // Es el reparto que devuelve ceros, y los ceros son donde una suma se
    // pierde sin que se note.
    for ($i = 0; $i < 70; $i++) {
        $cents = mt_rand(1, 50);
        $cases[] = [$cents, mt_rand($cents + 1, $cents + 200)];
    }

    // Créditos: el importe negativo se reparte igual de bien (SP-3).
    for ($i = 0; $i < 100; $i++) {
        $cases[] = [-mt_rand(1, 1_000_000), mt_rand(1, 36)];
    }

    // Importes grandes, muy por encima de cualquier precio real, para que el
    // reparto no dependa de que las cifras sean pequeñas.
    for ($i = 0; $i < 90; $i++) {
        $cases[] = [mt_rand(1, PHP_INT_MAX >> 20), mt_rand(1, 500)];
    }

    // Los bordes, sin azar: cero en cualquier número de partes, y una sola
    // parte que se lo lleva todo.
    $cases[] = [0, 1];
    $cases[] = [0, 7];
    $cases[] = [0, 999];
    $cases[] = [1, 1];
    $cases[] = [-1, 1];
    $cases[] = [1, 999];
    $cases[] = [-1, 999];
    $cases[] = [PHP_INT_MAX >> 10, 7];
    $cases[] = [PHP_INT_MAX >> 10, 1];
    $cases[] = [-(PHP_INT_MAX >> 10), 7];

    return $cases;
}

it('reparte 500 casos sin perder ni inventar un céntimo', function (): void {
    $cases = allocationCases();

    expect($cases)->toHaveCount(500);

    foreach ($cases as $index => [$cents, $parts]) {
        $allocated = (new Money($cents))->allocateEvenly($parts);

        $sum = array_sum(array_map(fn (Money $part): int => $part->cents, $allocated));
        $case = sprintf('caso #%d: %d céntimos en %d partes', $index, $cents, $parts);

        expect($sum)->toBe($cents, "La suma del reparto no cuadra · {$case}");
        expect($allocated)->toHaveCount($parts, "El reparto no tiene el número de partes pedido · {$case}");
    }
});

/**
 * El residuo va **en la última parte**, y no repartido ni en la primera. Lo
 * comprueba sobre los mismos 500 casos: las `n-1` primeras son exactamente la
 * división entera.
 */
it('deja el residuo en la última parte en los 500 casos', function (): void {
    foreach (allocationCases() as $index => [$cents, $parts]) {
        $allocated = (new Money($cents))->allocateEvenly($parts);
        $base = intdiv($cents, $parts);
        $case = sprintf('caso #%d: %d céntimos en %d partes', $index, $cents, $parts);

        $head = array_slice($allocated, 0, $parts - 1);

        foreach ($head as $position => $part) {
            expect($part->cents)->toBe($base, "La parte {$position} no es la división entera · {$case}");
        }

        expect(end($allocated)->cents)->toBe($cents - $base * ($parts - 1), "El residuo no quedó al final · {$case}");
    }
});

/**
 * SP-2, con nombre y con las cifras exactas de la spec.
 */
it('reparte $100 en tres cuotas dejando el céntimo en la última', function (): void {
    $parts = array_map(fn (Money $part): int => $part->cents, (new Money(10_000))->allocateEvenly(3));

    expect($parts)->toBe([3_333, 3_333, 3_334]);
    expect(array_sum($parts))->toBe(10_000);
});

it('devuelve el importe entero cuando la única parte es una', function (): void {
    $parts = (new Money(1_999))->allocateEvenly(1);

    expect($parts)->toHaveCount(1);
    expect($parts[0]->cents)->toBe(1_999);
});

it('divide exacto sin dejar residuo cuando toca', function (): void {
    $parts = array_map(fn (Money $part): int => $part->cents, (new Money(10_000))->allocateEvenly(4));

    expect($parts)->toBe([2_500, 2_500, 2_500, 2_500]);
});

/**
 * SP-3: un crédito se reparte como se reparte una deuda. `intdiv()` trunca
 * hacia cero, así que el céntimo sobrante va a la última parte con su signo.
 */
it('reparte un crédito igual que una deuda', function (): void {
    $parts = array_map(fn (Money $part): int => $part->cents, (new Money(-10_000))->allocateEvenly(3));

    expect($parts)->toBe([-3_333, -3_333, -3_334]);
    expect(array_sum($parts))->toBe(-10_000);
});

it('da todo a la última parte cuando hay más partes que céntimos', function (): void {
    $parts = array_map(fn (Money $part): int => $part->cents, (new Money(2))->allocateEvenly(5));

    expect($parts)->toBe([0, 0, 0, 0, 2]);
});

/**
 * SP-1. Repartir en cero partes devolvería una lista vacía que suma cero y se
 * llevaría el importe por delante sin que nadie lo notara.
 */
it('lanza al repartir en menos de una parte', function (int $parts): void {
    expect(fn (): array => (new Money(10_000))->allocateEvenly($parts))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1, -10]);

it('dice cuántos céntimos se iban a perder', function (): void {
    expect(fn (): array => (new Money(10_000))->allocateEvenly(0))
        ->toThrow(InvalidArgumentException::class, 'perdería los 10000 céntimos');
});

/**
 * El reparto conserva la moneda: una cuota de un importe en bolívares no puede
 * salir en dólares, o la suma de las partes dejaría de poder compararse con el
 * total.
 */
it('conserva la moneda en todas las partes', function (): void {
    $parts = (new Money(10_000, Currency::VES))->allocateEvenly(3);

    foreach ($parts as $part) {
        expect($part->currency)->toBe(Currency::VES);
    }
});
