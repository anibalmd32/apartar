<?php

declare(strict_types=1);

use App\Support\DecimalInput;

it('lleva cualquier forma usual de escribir un monto a punto decimal', function (string $typed, string $expected): void {
    expect(DecimalInput::normalize($typed))->toBe($expected);
})->with([
    'venezolano con miles' => ['90.000,00', '90000.00'],
    'venezolano sin miles' => ['25,50', '25.50'],
    'solo miles con punto' => ['1.275', '1275'],
    'varios puntos de miles' => ['1.250.000', '1250000'],
    'inglés con miles' => ['1,250.00', '1250.00'],
    'punto decimal' => ['25.50', '25.50'],
    'con símbolo' => ['Bs. 22.231,38', '22231.38'],
    'entero' => ['300', '300'],
]);

it('normaliza solo las claves pedidas y que traen texto', function (): void {
    expect(DecimalInput::normalizeKeys(['amount' => '1.275,50', 'rate' => '40,5', 'note' => 'x', 'empty' => ''], ['amount', 'empty', 'missing']))
        ->toBe(['amount' => '1275.50']);
});

it('en los formularios solo normaliza lo que tiene forma de número, para que la validación rechace el resto', function (): void {
    expect(DecimalInput::normalizeKeys(['a' => '-42,5', 'b' => 'cuarenta', 'c' => '1.234,56', 'd' => '36,5'], ['a', 'b', 'c', 'd']))
        ->toBe(['c' => '1234.56', 'd' => '36.5']);
});
