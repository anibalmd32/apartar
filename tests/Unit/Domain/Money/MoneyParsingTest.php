<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/**
 * `Money::fromDecimalString()`: la puerta por la que entran los datos sucios.
 *
 * Entra por cadena y no por `float` por el motivo de siempre —`"19.99"` como
 * `float` ya no vale 19,99 antes de que nadie lo multiplique—, y acepta muy
 * poco a propósito: un decimal llano, con signo negativo opcional y como mucho
 * dos decimales. Lo que no se entiende se para aquí y no tres capas más abajo
 * convertido en un precio plausible.
 */
it('parsea un decimal llano a céntimos', function (string $amount, int $cents): void {
    expect(Money::fromDecimalString($amount)->cents)->toBe($cents);
})->with([
    'dos decimales' => ['19.99', 1_999],
    'un decimal' => ['1.5', 150],
    'sin decimales' => ['1200', 120_000],
    'cero' => ['0', 0],
    'cero con decimales' => ['0.00', 0],
    'sin la parte entera' => ['.99', 99],
    'céntimo suelto' => ['0.01', 1],
    'negativo' => ['-19.99', -1_999],
    'negativo de menos de un dólar' => ['-0.50', -50],
    'negativo sin parte entera' => ['-.50', -50],
    'importe grande' => ['1000000.00', 100_000_000],
    'ceros a la izquierda' => ['007.50', 750],
]);

/**
 * El signo se conserva en la parte fraccionaria, que es donde se pierde si uno
 * parte la cadena por el punto y convierte los dos trozos por separado:
 * `(int) "-0"` es `0`, y `-0.50` se convertiría en `+50`.
 */
it('no pierde el signo de un importe menor que un dólar', function (): void {
    expect(Money::fromDecimalString('-0.50')->cents)->toBe(-50);
    expect(Money::fromDecimalString('-0.01')->cents)->toBe(-1);
});

it('respeta la moneda que se le pida', function (): void {
    expect(Money::fromDecimalString('19.99', Currency::VES)->currency)->toBe(Currency::VES);
    expect(Money::fromDecimalString('19.99')->currency)->toBe(Currency::USD);
});

/**
 * **Los separadores de millar se rechazan, y es la decisión que más importa de
 * esta frontera.** `"1.234"` son mil doscientos treinta y cuatro para quien
 * escribe en la convención venezolana y uno con pico en la nuestra: aceptarlo
 * obligaría a adivinar, y adivinar aquí es equivocarse por un factor de mil.
 */
it('rechaza los separadores de millar en vez de adivinar cuál es', function (string $amount): void {
    expect(fn (): Money => Money::fromDecimalString($amount))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'coma de millar' => ['1,234.56'],
    'punto de millar' => ['1.234.567'],
    'ya formateado en dólares' => ['$1,234.56'],
    'ya formateado en bolívares' => ['Bs. 1.234,56'],
    'coma decimal' => ['19,99'],
]);

/**
 * Más de dos decimales no es un importe: es un número al que alguien todavía
 * tiene que decidir cómo redondear, y esa decisión no la puede tomar un
 * constructor en silencio —redondearía el precio de algo sin decírselo a
 * quien lo escribió—.
 */
it('rechaza un tercer decimal en vez de redondearlo por su cuenta', function (): void {
    expect(fn (): Money => Money::fromDecimalString('19.999'))
        ->toThrow(InvalidArgumentException::class, 'decide tú cómo redondearlos');
});

it('rechaza lo que no es un decimal', function (string $amount): void {
    expect(fn (): Money => Money::fromDecimalString($amount))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'vacía' => [''],
    'espacios' => ['   '],
    'con espacios alrededor' => [' 19.99 '],
    'palabras' => ['diecinueve con noventa y nueve'],
    'exponencial' => ['1e3'],
    'exponencial con decimales' => ['1.9e2'],
    'hexadecimal' => ['0x1F'],
    'punto suelto' => ['.'],
    'punto final' => ['19.'],
    'dos puntos' => ['19.9.9'],
    'signo suelto' => ['-'],
    'signo positivo explícito' => ['+19.99'],
    'infinito' => ['INF'],
    'no numérico' => ['NaN'],
    'guion largo' => ['—19.99'],
]);

/**
 * El mensaje enseña la entrada y los ejemplos válidos: quien lo lea en un log
 * tiene que poder ver de un vistazo qué le pasa a su cadena.
 */
it('dice qué llegó y qué se admite', function (): void {
    expect(fn (): Money => Money::fromDecimalString('1,234.56'))
        ->toThrow(InvalidArgumentException::class, '"1,234.56"');

    expect(fn (): Money => Money::fromDecimalString('1,234.56'))
        ->toThrow(InvalidArgumentException::class, 'separadores de millar');
});

/**
 * La vuelta completa: lo que escribe `MoneyFormatter` no vuelve a entrar por
 * aquí, y es intencionado. El formateo es para personas y el parseo para
 * datos; que una cadena con el símbolo y los millares se rechace es lo que
 * impide que alguien encadene las dos y crea que tiene un ciclo.
 */
it('no acepta de vuelta lo que el formateador escribe para una persona', function (): void {
    expect(fn (): Money => Money::fromDecimalString('$20.00'))
        ->toThrow(InvalidArgumentException::class);
});
