<?php

declare(strict_types=1);

use App\Domain\Layaway\NotAcceptingLayawaysException;

/**
 * El mensaje neutro del cupo agotado (`REQ-LAY-001` SP-2, `REQ-SUB-004` RB-4).
 */
it('dice al cliente una frase neutra y guarda el motivo real dentro', function (): void {
    $reason = new RuntimeException('Tu plan Free permite 5 apartados activos. Pro permite 50.');

    $exception = NotAcceptingLayawaysException::because($reason);

    expect($exception->getMessage())->toBe('Este negocio no puede recibir nuevos apartados en este momento.')
        ->and($exception->getMessage())->not->toContain('Free')
        ->and($exception->getMessage())->not->toContain('plan')
        ->and($exception->getPrevious())->toBe($reason)
        ->and(NotAcceptingLayawaysException::STATUS)->toBe(422);
});
