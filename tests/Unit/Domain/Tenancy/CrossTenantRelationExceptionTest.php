<?php

declare(strict_types=1);

use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;

it('nombra el modelo y las dos organizaciones al crear una fila ajena', function (): void {
    $exception = CrossTenantRelationException::whileCreating(Organization::class, 11, 3);

    expect($exception->getMessage())
        ->toContain(Organization::class)
        ->toContain('11')
        ->toContain('3');
});

it('nombra los dos modelos de una relación cruzada', function (): void {
    $exception = CrossTenantRelationException::between(TenantContext::class, Organization::class);

    expect($exception->getMessage())
        ->toContain(TenantContext::class)
        ->toContain(Organization::class)
        ->toContain('organizaciones distintas');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(CrossTenantRelationException::between(Organization::class, Organization::class))->toBeInstanceOf(RuntimeException::class);
});
