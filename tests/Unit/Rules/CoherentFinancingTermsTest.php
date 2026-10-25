<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Rules\CoherentFinancingTerms;

/**
 * @return array<string, string>
 */
function terms(
    int $price = 25_000,
    ?int $compareAt = null,
    int $down = 5_000,
    int $installments = 4,
    int $frequency = 15,
    int $stock = 10,
): array {
    return CoherentFinancingTerms::violationsIn(
        new Money($price),
        $compareAt === null ? null : new Money($compareAt),
        new Money($down),
        $installments,
        $frequency,
        $stock,
    );
}

it('acepta unos términos corrientes', function (): void {
    expect(terms())->toBe([]);
});

it('exige que la inicial sea menor que el precio', function (int $down, bool $valid): void {
    $violations = terms(price: 10_000, down: $down);

    expect(array_key_exists('down_payment_cents', $violations))->toBe(! $valid);
})->with([
    'un céntimo por debajo' => [9_999, true],
    'igual al precio' => [10_000, false],
    'por encima del precio' => [10_001, false],
    'sin inicial' => [0, true],
    'negativa' => [-1, false],
]);

it('exige que el precio tachado sea mayor que el precio', function (?int $compareAt, bool $valid): void {
    $violations = terms(price: 10_000, compareAt: $compareAt);

    expect(array_key_exists('compare_at_price', $violations))->toBe(! $valid);
})->with([
    'mayor' => [10_001, true],
    'igual' => [10_000, false],
    'menor' => [9_999, false],
    'ausente' => [null, true],
]);

it('acota el número de cuotas entre 1 y 36', function (int $installments, bool $valid): void {
    expect(array_key_exists('installments_count', terms(installments: $installments)))->toBe(! $valid);
})->with([
    'ninguna' => [0, false],
    'una' => [1, true],
    'treinta y seis' => [36, true],
    'treinta y siete' => [37, false],
    'negativa' => [-1, false],
]);

it('exige al menos un céntimo por cuota', function (int $price, int $down, int $installments, bool $valid): void {
    $violations = terms(price: $price, down: $down, installments: $installments);

    expect(array_key_exists('installments_count', $violations))->toBe(! $valid);
})->with([
    '$0,05 en 36' => [105, 100, 36, false],
    'un céntimo por cuota, justo' => [136, 100, 36, true],
    'un céntimo de menos' => [135, 100, 36, false],
    'un céntimo en una cuota' => [101, 100, 1, true],
]);

it('dice cuántas cuotas caben como mucho', function (): void {
    expect(terms(price: 105, down: 100, installments: 36)['installments_count'] ?? null)
        ->toBe('Con $0,05 por financiar caben como mucho 5 cuotas: cada cuota tiene que ser de al menos un céntimo.');
});

it('no repite el aviso cuando la inicial o las cuotas ya están mal', function (): void {
    expect(terms(price: 105, down: 105, installments: 36))->not->toHaveKey('installments_count');
    expect(terms(price: 105, down: 100, installments: 37)['installments_count'] ?? null)->toStartWith('El número de cuotas tiene que estar entre');
});

it('acota los días entre cuotas entre 1 y 90', function (int $frequency, bool $valid): void {
    expect(array_key_exists('frequency_days', terms(frequency: $frequency)))->toBe(! $valid);
})->with([
    'cero' => [0, false],
    'uno' => [1, true],
    'noventa' => [90, true],
    'noventa y uno' => [91, false],
]);

it('acota el precio entre un céntimo y un millón', function (int $price, bool $valid): void {
    expect(array_key_exists('price', terms(price: $price, down: 0)))->toBe(! $valid);
})->with([
    'cero' => [0, false],
    'negativo' => [-1, false],
    'un céntimo' => [1, true],
    'un millón' => [CoherentFinancingTerms::MAX_PRICE_CENTS, true],
    'un céntimo de más' => [CoherentFinancingTerms::MAX_PRICE_CENTS + 1, false],
]);

it('rechaza existencias negativas', function (int $stock, bool $valid): void {
    expect(array_key_exists('stock', terms(stock: $stock)))->toBe(! $valid);
})->with([
    'agotado' => [0, true],
    'con existencias' => [7, true],
    'negativas' => [-1, false],
]);

it('devuelve todas las reglas incumplidas de una vez', function (): void {
    $violations = terms(price: 0, compareAt: 0, down: 0, installments: 99, frequency: 0, stock: -1);

    expect(array_keys($violations))->toEqualCanonicalizing([
        'price',
        'down_payment_cents',
        'compare_at_price',
        'installments_count',
        'frequency_days',
        'stock',
    ]);
});

it('deja pasar un producto agotado', function (): void {
    expect(terms(stock: 0))->toBe([]);
});
