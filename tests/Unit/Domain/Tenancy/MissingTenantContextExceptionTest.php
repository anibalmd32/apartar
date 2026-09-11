<?php

declare(strict_types=1);

use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Organization;

/**
 * El mensaje de esta excepción es lo que alguien lee a las tres de la mañana
 * cuando un job se cae en producción: tiene que decir qué faltaba y cómo se
 * arregla, no solo que algo no estaba.
 */
it('nombra el modelo y la salida al fallar una consulta', function (): void {
    $exception = MissingTenantContextException::whileQuerying(Organization::class);

    expect($exception->getMessage())
        ->toContain(Organization::class)
        ->toContain('setOrganization')
        ->toContain('withoutTenantScope');
});

it('nombra el modelo y recuerda quién rellena el tenant al fallar una creación', function (): void {
    $exception = MissingTenantContextException::whileCreating(Organization::class);

    expect($exception->getMessage())
        ->toContain(Organization::class)
        ->toContain('runFor');
});

it('explica quién fija el tenant al leerlo sin haberlo fijado', function (): void {
    expect(MissingTenantContextException::whileReading()->getMessage())
        ->toContain('middleware')
        ->toContain('payload');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(MissingTenantContextException::whileReading())->toBeInstanceOf(RuntimeException::class);
});
