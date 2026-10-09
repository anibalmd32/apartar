<?php

declare(strict_types=1);

use App\Domain\Customer\NoEmailToResendException;

/**
 * Reenviar el código a una ficha sin correo (`REQ-BOF-041` SP-7, `T-065`): no
 * hay a dónde, y se ofrece dictarlo.
 */
it('sin correo, se ofrece dictar el código', function (): void {
    expect(NoEmailToResendException::STATUS)->toBe(422)
        ->and((new NoEmailToResendException)->getMessage())->toContain('díctaselo');
});
