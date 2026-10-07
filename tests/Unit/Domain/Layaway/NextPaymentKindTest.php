<?php

declare(strict_types=1);

use App\Domain\Layaway\NextPaymentKind;

/**
 * Los valores viajan al frontend tal cual (`kind` en `types/portal.ts` y
 * `types/receipt.ts`): cambiarlos rompe dos pantallas sin un error de PHP.
 */
it('declara la inicial y la cuota con los valores que lee el frontend', function (): void {
    expect(array_column(NextPaymentKind::cases(), 'value'))->toBe(['down_payment', 'installment']);
});
