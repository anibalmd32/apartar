<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Rules\CoherentFinancingTerms;

/**
 * **La matriz de las seis reglas de `REQ-BOF-020` RB-1**, con sus casos
 * límite.
 *
 * Se prueban contra `violationsIn()` y no atravesando un `FormRequest` porque
 * es donde las seis están escritas: aquí cabe la matriz entera con sus bordes
 * —`down_payment == price`, `compare_at_price == price`, 36 cuotas y 37— sin
 * montar una petición por celda. Que además lleguen al formulario junto a su
 * campo lo comprueba `ProductPageTest`.
 *
 * Cada regla impide un producto distinto que el sistema aceptaría y luego no
 * sabría cobrar, y el síntoma no aparece aquí: aparece en H3, cuando
 * `ScheduleGenerator` intente convertir esas cifras en un cronograma.
 */
/**
 * @return array<string, string> campo => motivo
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

/**
 * SP-1 · **estrictamente** menor. El caso límite es el que importa: con la
 * inicial igual al precio no queda nada que financiar, y el apartado sería una
 * compra al contado disfrazada de plan de pago.
 */
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

/**
 * SP-2 · el precio tachado tiene que ser **mayor**. Igual o menor convierte
 * una oferta en un engaño visible en la pantalla del cliente.
 */
it('exige que el precio tachado sea mayor que el precio', function (?int $compareAt, bool $valid): void {
    $violations = terms(price: 10_000, compareAt: $compareAt);

    expect(array_key_exists('compare_at_price', $violations))->toBe(! $valid);
})->with([
    'mayor' => [10_001, true],
    'igual' => [10_000, false],
    'menor' => [9_999, false],
    'ausente' => [null, true],
]);

/**
 * Los dos extremos del cronograma que `ScheduleGenerator` va a recorrer.
 */
it('acota el número de cuotas entre 1 y 36', function (int $installments, bool $valid): void {
    expect(array_key_exists('installments_count', terms(installments: $installments)))->toBe(! $valid);
})->with([
    'ninguna' => [0, false],
    'una' => [1, true],
    'treinta y seis' => [36, true],
    'treinta y siete' => [37, false],
    'negativa' => [-1, false],
]);

it('acota los días entre cuotas entre 1 y 90', function (int $frequency, bool $valid): void {
    expect(array_key_exists('frequency_days', terms(frequency: $frequency)))->toBe(! $valid);
})->with([
    'cero' => [0, false],
    'uno' => [1, true],
    'noventa' => [90, true],
    'noventa y uno' => [91, false],
]);

/**
 * El precio, con su cero y su millón.
 */
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

/**
 * Los seis fallos a la vez llegan juntos, cada uno con su campo: quien envía
 * un formulario roto lo arregla de una vez y no en seis viajes.
 */
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

/**
 * Y `stock = 0` **no** es un fallo: agotado no cambia el estado del producto
 * (`REQ-CAT-007` RB-4). Sigue publicado, visible con su sello, y lo que se
 * deshabilita es apartarlo.
 */
it('deja pasar un producto agotado', function (): void {
    expect(terms(stock: 0))->toBe([]);
});
