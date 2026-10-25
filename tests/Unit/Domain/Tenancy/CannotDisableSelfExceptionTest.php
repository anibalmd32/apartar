<?php

declare(strict_types=1);

use App\Domain\Tenancy\CannotDisableSelfException;

it('explica el motivo y a quién pedírselo', function (): void {
    expect(CannotDisableSelfException::make()->getMessage())
        ->toContain('tu propia cuenta')
        ->toContain('otro owner');
});

it('se responde con 422 y no con 403', function (): void {
    expect(CannotDisableSelfException::STATUS)->toBe(422);
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(CannotDisableSelfException::make())->toBeInstanceOf(RuntimeException::class);
});
