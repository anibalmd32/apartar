<?php

declare(strict_types=1);

use App\Domain\Payment\IdempotencyKeyReusedException;

/**
 * Una clave que ya registró otro pago (`REQ-PAY-007` SP-5): un `422` que pide
 * recargar, no un «pago registrado» de un pago que no se registró.
 */
it('responde con un 422 y pide recargar', function (): void {
    expect(IdempotencyKeyReusedException::STATUS)->toBe(422)
        ->and((new IdempotencyKeyReusedException)->getMessage())->toContain('Recarga la página');
});
