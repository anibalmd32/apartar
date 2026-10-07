<?php

declare(strict_types=1);

use App\Domain\Payment\DuplicateCriterion;

/**
 * Los dos criterios de `REQ-PAY-003` RB-2 (`T-059`). Sus valores viajan al
 * formulario, así que no cambian sin que algo lo note.
 */
it('tiene exactamente los dos criterios de RB-2', function (): void {
    expect(array_map(fn (DuplicateCriterion $criterion): string => $criterion->value, DuplicateCriterion::cases()))
        ->toBe(['file_hash', 'bank_reference']);
});
