<?php

declare(strict_types=1);

use App\Domain\Tenancy\LastOwnerException;

it('nombra a la persona, la operación y la salida', function (): void {
    $exception = LastOwnerException::wouldLeaveNone('Ana', 'degradar');

    expect($exception->getMessage())
        ->toContain('Ana')
        ->toContain('degradar')
        ->toContain('último owner')
        ->toContain('Asciende');
});

it('se responde con 422 y no con 403', function (): void {
    expect(LastOwnerException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(LastOwnerException::wouldLeaveNone('Ana', 'desactivar'))->toBeInstanceOf(RuntimeException::class);
});
