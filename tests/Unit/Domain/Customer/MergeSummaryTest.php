<?php

declare(strict_types=1);

use App\Domain\Customer\MergeSummary;

/**
 * Lo que mueve una fusión de fichas (`REQ-IDN-006` RB-2, `T-065`), para el
 * resumen que se confirma y para la auditoría.
 */
it('dice qué movió', function (): void {
    expect((new MergeSummary(2, 3, 1, true))->toArray())->toBe(['layaways' => 2, 'receipts' => 3, 'login_tokens' => 1, 'account_moves' => true]);
});
