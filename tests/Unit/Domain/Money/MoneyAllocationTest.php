<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\Money;

const ALLOCATION_SEED = 20_260_918;

/**
 * @return list<array{0: int, 1: int}>
 */
function allocationCases(): array
{
    mt_srand(ALLOCATION_SEED);

    $cases = [];

    for ($i = 0; $i < 150; $i++) {
        $cases[] = [mt_rand(1, 1_000_000), mt_rand(1, 24)];
    }

    foreach ([7, 13, 97, 101, 997, 10_007, 99_991, 1_000_003] as $prime) {
        for ($i = 0; $i < 10; $i++) {
            $cases[] = [$prime, mt_rand(2, 60)];
        }
    }

    for ($i = 0; $i < 70; $i++) {
        $cents = mt_rand(1, 50);
        $cases[] = [$cents, mt_rand($cents + 1, $cents + 200)];
    }

    for ($i = 0; $i < 100; $i++) {
        $cases[] = [-mt_rand(1, 1_000_000), mt_rand(1, 36)];
    }

    for ($i = 0; $i < 90; $i++) {
        $cases[] = [mt_rand(1, PHP_INT_MAX >> 20), mt_rand(1, 500)];
    }

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

it('reparte un crédito igual que una deuda', function (): void {
    $parts = array_map(fn (Money $part): int => $part->cents, (new Money(-10_000))->allocateEvenly(3));

    expect($parts)->toBe([-3_333, -3_333, -3_334]);
    expect(array_sum($parts))->toBe(-10_000);
});

it('da todo a la última parte cuando hay más partes que céntimos', function (): void {
    $parts = array_map(fn (Money $part): int => $part->cents, (new Money(2))->allocateEvenly(5));

    expect($parts)->toBe([0, 0, 0, 0, 2]);
});

it('lanza al repartir en menos de una parte', function (int $parts): void {
    expect(fn (): array => (new Money(10_000))->allocateEvenly($parts))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1, -10]);

it('dice cuántos céntimos se iban a perder', function (): void {
    expect(fn (): array => (new Money(10_000))->allocateEvenly(0))
        ->toThrow(InvalidArgumentException::class, 'perdería los 10000 céntimos');
});

it('conserva la moneda en todas las partes', function (): void {
    $parts = (new Money(10_000, Currency::VES))->allocateEvenly(3);

    foreach ($parts as $part) {
        expect($part->currency)->toBe(Currency::VES);
    }
});
