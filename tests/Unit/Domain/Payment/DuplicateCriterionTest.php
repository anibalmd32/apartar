<?php

declare(strict_types=1);

use App\Domain\Payment\DuplicateCriterion;

it('tiene exactamente los dos criterios', function (): void {
    expect(array_map(fn (DuplicateCriterion $criterion): string => $criterion->value, DuplicateCriterion::cases()))
        ->toBe(['file_hash', 'bank_reference']);
});
