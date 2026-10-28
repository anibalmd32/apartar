<?php

declare(strict_types=1);

use App\Domain\Catalog\Savings;
use App\Domain\Money\Money;

it('calcula el porcentaje redondeado que se ahorra frente al precio anterior', function (): void {
    expect(Savings::percent(new Money(15_000), new Money(18_000)))->toBe(17)
        ->and(Savings::percent(new Money(5_000), new Money(10_000)))->toBe(50);
});

it('sin precio anterior, o si no es mayor, no hay ahorro', function (): void {
    expect(Savings::percent(new Money(15_000), null))->toBeNull()
        ->and(Savings::percent(new Money(15_000), new Money(15_000)))->toBeNull()
        ->and(Savings::percent(new Money(15_000), new Money(12_000)))->toBeNull();
});
