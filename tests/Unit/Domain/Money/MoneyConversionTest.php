<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * La conversión de `REQ-FX-001` RB-5: `bcmul` a escala 8 y **half-up a dos
 * decimales una sola vez, al final**.
 *
 * El error clásico de esta operación no se ve en una cifra: se ve al sumar
 * cuarenta cuotas, cuando el total en bolívares deja de coincidir con el total
 * en dólares convertido. Por eso el test que de verdad sostiene la regla no es
 * el de `$20 × 42,50`, sino el del cronograma entero de más abajo.
 */
function rateOf(string $rate, RateSource $source = RateSource::Bcv): RateSnapshot
{
    return new RateSnapshot($rate, $source, CarbonImmutable::now(), CarbonImmutable::now());
}

/**
 * El happy path de la spec: `$20.00` a la tasa 42,50 son `Bs. 850,00`.
 */
it('convierte de dólares a bolívares', function (): void {
    $converted = (new Money(2_000))->convertTo(Currency::VES, rateOf('42.50000000'));

    expect($converted->cents)->toBe(85_000);
    expect($converted->currency)->toBe(Currency::VES);
});

/**
 * `REQ-FX-006` RB-2: un pago declarado en bolívares se concilia en dólares. Es
 * la misma operación recíproca, con la misma escala y el mismo redondeo único.
 */
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

/**
 * `REQ-FX-005` RB-1: **nunca** se bloquea la navegación por la tasa. Con una
 * fila corrupta en el histórico, pintar un precio en dólares tiene que seguir
 * funcionando; validar antes de mirar si hay algo que convertir apagaría el
 * catálogo entero por un dato que esa página no usa.
 */
it('no mira la tasa cuando no hay nada que convertir', function (string $rate): void {
    expect((new Money(1_999))->convertTo(Currency::USD, rateOf($rate))->cents)->toBe(1_999);
})->with(['0', '-1', 'cuarenta y dos', '']);

/**
 * El redondeo es *half away from zero*, y es el único simétrico: convertir un
 * crédito tiene que dar exactamente lo contrario que convertir un cobro, o el
 * saldo de quien pagó de más dependería del signo.
 */
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

/**
 * **La prueba que detecta el redondeo intermedio.** Un cronograma de cuarenta
 * cuotas se convierte cuota a cuota y también de una vez, y las dos cifras
 * tienen que coincidir **al céntimo**.
 *
 * Es el escenario real de un apartado: el cliente ve el total en bolívares en
 * la ficha y luego ve cuarenta cuotas en bolívares en su cronograma. Si no
 * cuadran, lo nota él antes que nadie.
 *
 * Las entradas son fijas, así que el resultado es determinista y la igualdad
 * exacta es exigible: **cualquier** diferencia significa que la aritmética
 * cambió. Un redondeo colado antes de la última operación las separa de
 * inmediato —una cuota de 3086 céntimos a una tasa con ocho decimales pierde
 * la cola en cada una de las cuarenta vueltas—.
 */
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

/**
 * La vuelta entera: dólares a bolívares y de vuelta a dólares con la misma
 * tasa devuelve el importe de partida. No es una identidad matemática exigible
 * con cualquier tasa —el redondeo al céntimo pierde información—, pero con
 * tasas razonables tiene que cerrar, y si no cierra es que se redondeó de más.
 */
it('vuelve al importe de partida en el viaje de ida y vuelta', function (string $rate): void {
    $original = new Money(2_000);

    $roundTrip = $original
        ->convertTo(Currency::VES, rateOf($rate))
        ->convertTo(Currency::USD, rateOf($rate));

    expect($roundTrip->cents)->toBe($original->cents);
})->with(['42.50000000', '36.12000000', '1.00000000', '250.00000000']);

/**
 * SP-4, y el **antes** del enunciado importa: una tasa `0` no revienta
 * `bcmul`, devuelve `0`. Un precio de cero bolívares se pinta igual de bien
 * que uno correcto y solo se descubre cuando alguien intenta cobrarlo.
 */
it('lanza antes de convertir con una tasa que no es positiva', function (string $rate): void {
    expect(fn (): Money => (new Money(2_000))->convertTo(Currency::VES, rateOf($rate)))
        ->toThrow(InvalidRateException::class);
})->with(['0', '0.00000000', '-1', '-42.50000000', '0.0']);

it('nombra la tasa que rechazó', function (): void {
    expect(fn (): Money => (new Money(2_000))->convertTo(Currency::VES, rateOf('0')))
        ->toThrow(InvalidRateException::class, 'no es mayor que cero');
});

/**
 * `RateSnapshot::rate` es una cadena libre —lo es para que nunca pase por
 * `float`—, así que puede llegar cualquier cosa. Se comprueba la forma antes
 * que el valor porque `bcmath` en PHP 8 lanza un `ValueError` que habla de
 * argumentos y no de tasas, y quien lo lea en un log no sabrá que lo que hay
 * que mirar es el parser de la fuente.
 */
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

/**
 * La tasa no pasa por `float` en ningún punto: se opera sobre la cadena con
 * los ocho decimales que declara `exchange_rates.rate DECIMAL(18,8)`.
 */
it('usa los ocho decimales de la tasa sin pasar por float', function (): void {
    // Una tasa con ocho decimales sobre un importe grande: si la cadena pasara
    // por float, el resultado se desviaría en los últimos dígitos.
    $converted = (new Money(100_000_000))->convertTo(Currency::VES, rateOf('42.12345678'));

    expect($converted->cents)->toBe(4_212_345_678);
});

it('convierte un crédito conservando el signo', function (): void {
    expect((new Money(-2_000))->convertTo(Currency::VES, rateOf('42.50000000'))->cents)->toBe(-85_000);
});

it('convierte cero a cero', function (): void {
    expect((new Money(0))->convertTo(Currency::VES, rateOf('42.50000000'))->cents)->toBe(0);
});
