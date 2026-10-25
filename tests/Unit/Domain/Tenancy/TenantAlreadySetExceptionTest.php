<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantAlreadySetException;

it('nombra las dos organizaciones y la vía legítima para cambiar', function (): void {
    $exception = TenantAlreadySetException::cannotSwitch(3, 11);

    expect($exception->getMessage())
        ->toContain('3')
        ->toContain('11')
        ->toContain('runFor');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(TenantAlreadySetException::cannotSwitch(1, 2))->toBeInstanceOf(RuntimeException::class);
});
