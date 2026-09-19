<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;

/**
 * El formateo de `REQ-FX-001` RB-7, declarado y diferido a `T-018`.
 *
 * **La batería exacta —`$1,234.56` y `Bs. 1.234,56`— es de `T-018`** y es una
 * de las razones de que esa tarea exista: las dos convenciones son opuestas y
 * una cifra con los separadores cambiados no parece un error, parece otro
 * precio. Lo que se fija aquí es que el sitio del formateo es **uno solo** y
 * que mientras esté vacío lo diga a gritos.
 */
it('deja el formateo declarado y lanzando, nombrando a T-018', function (): void {
    expect(fn (): string => MoneyFormatter::format(new Money(123_456)))
        ->toThrow(LogicException::class, 'T-018');
});

it('dice qué importe se le pidió escribir', function (): void {
    expect(fn (): string => MoneyFormatter::format(new Money(85_000, Currency::VES)))
        ->toThrow(LogicException::class, 'escribir 85000 céntimos de VES');
});

/**
 * El formateo vive en el servidor y viaja resuelto en `MoneyView` (RB-6): el
 * frontend elige cuál de las dos representaciones enseña, no las construye.
 * Esta firma es la que lo hace posible —recibe el importe entero, con su
 * moneda— en lugar de recibir céntimos sueltos y un símbolo.
 */
it('formatea a partir del importe completo, con su moneda', function (): void {
    $method = new ReflectionMethod(MoneyFormatter::class, 'format');

    expect($method->isStatic())->toBeTrue();
    expect((string) $method->getParameters()[0]->getType())->toBe(Money::class);
    expect((string) $method->getReturnType())->toBe('string');
});
