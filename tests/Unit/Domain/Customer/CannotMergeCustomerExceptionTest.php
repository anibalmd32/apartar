<?php

declare(strict_types=1);

use App\Domain\Customer\CannotMergeCustomerException;

/**
 * Una fusión de fichas que no se puede hacer (`REQ-IDN-006`, `T-065`): cada
 * negativa con su motivo, y un `422`.
 */
it('cada negativa de la fusión dice por qué', function (): void {
    expect(CannotMergeCustomerException::STATUS)->toBe(422)
        ->and(CannotMergeCustomerException::sameRecord()->getMessage())->toContain('consigo misma')
        ->and(CannotMergeCustomerException::gone()->getMessage())->toContain('ya no existe')
        ->and(CannotMergeCustomerException::otherOrganization()->getMessage())->toContain('tu propio negocio')
        ->and(CannotMergeCustomerException::bothLinked()->getMessage())->toContain('cuentas distintas')
        ->and(CannotMergeCustomerException::unconfirmed()->getMessage())->toContain('escribe el documento');
});
