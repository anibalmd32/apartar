<?php

declare(strict_types=1);

use App\Domain\Money\Currency;

/**
 * Las dos monedas del contrato del módulo 13.
 */
it('declara exactamente USD y VES', function (): void {
    expect(array_column(Currency::cases(), 'value'))->toBe(['USD', 'VES']);
});

/**
 * El valor se persiste y viaja a TypeScript tal cual: cambiarlo no es un
 * renombrado, es una migración.
 */
it('usa el código ISO como valor', function (Currency $currency, string $value): void {
    expect($currency->value)->toBe($value);
})->with([
    [Currency::USD, 'USD'],
    [Currency::VES, 'VES'],
]);

/**
 * `Money` toma USD por defecto porque **la deuda es siempre en USD**
 * (`ASU-FX-04`). Que el default esté en el constructor y no escrito en cada
 * llamada es lo que evita que alguien cree un importe en bolívares por
 * descuido y lo sume a un saldo.
 */
it('no lleva símbolo ni formato, que son de MoneyFormatter', function (): void {
    $methods = get_class_methods(Currency::class);

    expect($methods)->not->toContain('symbol');
    expect($methods)->not->toContain('format');
});
