<?php

declare(strict_types=1);

use App\Domain\Money\Currency;

it('declara exactamente USD y VES', function (): void {
    expect(array_column(Currency::cases(), 'value'))->toBe(['USD', 'VES']);
});

it('usa el código ISO como valor', function (Currency $currency, string $value): void {
    expect($currency->value)->toBe($value);
})->with([
    [Currency::USD, 'USD'],
    [Currency::VES, 'VES'],
]);

it('no lleva símbolo ni formato, que son de MoneyFormatter', function (): void {
    $methods = get_class_methods(Currency::class);

    expect($methods)->not->toContain('symbol');
    expect($methods)->not->toContain('format');
});

it('se nombra con su código para elegirla en un formulario', function (Currency $currency, string $label): void {
    expect($currency->label())->toBe($label)->toContain($currency->value);
})->with([
    [Currency::USD, 'Dólares (USD)'],
    [Currency::VES, 'Bolívares (VES)'],
]);
