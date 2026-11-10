<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Safeguard\ParallelEquivalent;

it('la diferencia es lo que vale en paralelo menos lo acreditado', function (): void {
    $equivalent = new ParallelEquivalent(new Money(10_000), new Money(8_421), '1067.89');

    expect($equivalent->known())->toBeTrue()
        ->and($equivalent->gap()?->cents)->toBe(-1_579);
});

it('sin tasa paralela no se conoce el equivalente ni la diferencia', function (): void {
    $equivalent = ParallelEquivalent::missing(new Money(10_000));

    expect($equivalent->known())->toBeFalse()
        ->and($equivalent->gap())->toBeNull()
        ->and($equivalent->credited->cents)->toBe(10_000);
});
