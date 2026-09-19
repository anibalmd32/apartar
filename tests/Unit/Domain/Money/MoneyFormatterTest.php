<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;

/**
 * El formateo de `REQ-FX-001` RB-7, exacto y fijado con cadenas literales.
 *
 * **Las dos convenciones están invertidas entre sí**: USD `$1,234.56` —coma de
 * miles, punto decimal— y VES `Bs. 1.234,56` —punto de miles, coma decimal—.
 * No es un descuido de la spec, es la convención local, y una cifra con los
 * separadores cambiados no parece un error: parece otro precio.
 *
 * Todas las aserciones son cadenas literales y ninguna se construye con la
 * misma función que se está probando. Es lo que hace que este archivo valga:
 * una prueba que comparase contra `number_format()` pasaría igual de bien con
 * el formateo equivocado.
 */
it('escribe los dólares con coma de miles y punto decimal', function (int $cents, string $expected): void {
    expect(MoneyFormatter::format(new Money($cents, Currency::USD)))->toBe($expected);
})->with([
    'el ejemplo de RB-7' => [123_456, '$1,234.56'],
    'el del happy path' => [2_000, '$20.00'],
    'cero' => [0, '$0.00'],
    'un céntimo' => [1, '$0.01'],
    'menos de un dólar' => [50, '$0.50'],
    'sin decimales' => [1_500, '$15.00'],
    'justo antes del millar' => [99_999, '$999.99'],
    'justo en el millar' => [100_000, '$1,000.00'],
    'dos grupos' => [123_456_789, '$1,234,567.89'],
    'tres grupos' => [123_456_789_012, '$1,234,567,890.12'],
    'el tope del CRUD' => [100_000_000, '$1,000,000.00'],
    'negativo' => [-2_000, '-$20.00'],
    'negativo con millares' => [-123_456, '-$1,234.56'],
    'negativo de céntimos' => [-1, '-$0.01'],
]);

it('escribe los bolívares con punto de miles y coma decimal', function (int $cents, string $expected): void {
    expect(MoneyFormatter::format(new Money($cents, Currency::VES)))->toBe($expected);
})->with([
    'el ejemplo de RB-7' => [123_456, 'Bs. 1.234,56'],
    'el del happy path' => [85_000, 'Bs. 850,00'],
    'cero' => [0, 'Bs. 0,00'],
    'un céntimo' => [1, 'Bs. 0,01'],
    'sin decimales' => [1_500, 'Bs. 15,00'],
    'justo en el millar' => [100_000, 'Bs. 1.000,00'],
    'dos grupos' => [123_456_789, 'Bs. 1.234.567,89'],
    'tres grupos' => [123_456_789_012, 'Bs. 1.234.567.890,12'],
    'negativo' => [-85_000, '-Bs. 850,00'],
    'negativo con millares' => [-123_456, '-Bs. 1.234,56'],
]);

/**
 * La aserción que fija que **no están al derecho las dos**: si alguien copiara
 * la convención de una moneda a la otra, este test es el que se pone rojo.
 */
it('mantiene las dos convenciones invertidas entre sí', function (): void {
    $usd = MoneyFormatter::format(new Money(123_456, Currency::USD));
    $ves = MoneyFormatter::format(new Money(123_456, Currency::VES));

    expect($usd)->toBe('$1,234.56');
    expect($ves)->toBe('Bs. 1.234,56');

    // El separador de millar de una es el decimal de la otra, y al revés.
    expect(substr_count($usd, ','))->toBe(1);
    expect(substr_count($usd, '.'))->toBe(1);
    expect(strpos($usd, ','))->toBeLessThan((int) strpos($usd, '.'));
    expect(strpos($ves, '.'))->toBeLessThan((int) strpos($ves, ','));
});

/**
 * **El formateo no depende del `locale` del sistema ni de la versión de ICU.**
 *
 * Es el motivo de que no use `NumberFormatter`: con él, el mismo código
 * escribiría el dinero distinto en el contenedor y en la máquina de alguien, y
 * nadie habría tocado nada. Aquí se cambia el `locale` del proceso a uno con
 * otra convención y la salida no se mueve.
 */
it('escribe igual bajo cualquier locale del sistema', function (): void {
    $original = setlocale(LC_ALL, '0');

    try {
        foreach (['C', 'de_DE.UTF-8', 'de_DE', 'en_US.UTF-8', 'es_VE.UTF-8'] as $locale) {
            setlocale(LC_ALL, $locale);

            expect(MoneyFormatter::format(new Money(123_456, Currency::USD)))->toBe('$1,234.56');
            expect(MoneyFormatter::format(new Money(123_456, Currency::VES)))->toBe('Bs. 1.234,56');
        }
    } finally {
        setlocale(LC_ALL, (string) $original);
    }
});

/**
 * Ni por `float` ni por `number_format()`, que recibe uno. Con importes por
 * encima de los nueve billones de céntimos, un `float` ya no representa cada
 * entero y la cifra escrita dejaría de ser la guardada.
 */
it('escribe un importe enorme sin perder un céntimo', function (): void {
    expect(MoneyFormatter::format(new Money(9_007_199_254_740_993)))->toBe('$90,071,992,547,409.93');
});

/**
 * El formateo vive en el servidor y viaja resuelto en `MoneyView` (RB-6): el
 * frontend elige cuál de las dos representaciones enseña, no las construye.
 * Esta firma es la que lo hace posible —recibe el importe entero, con su
 * moneda— en lugar de céntimos sueltos y un símbolo.
 */
it('formatea a partir del importe completo, con su moneda', function (): void {
    $method = new ReflectionMethod(MoneyFormatter::class, 'format');

    expect($method->isStatic())->toBeTrue();
    expect((string) $method->getParameters()[0]->getType())->toBe(Money::class);
    expect((string) $method->getReturnType())->toBe('string');
});
