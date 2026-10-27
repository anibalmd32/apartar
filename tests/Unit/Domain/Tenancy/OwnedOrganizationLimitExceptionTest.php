<?php

declare(strict_types=1);

use App\Domain\Tenancy\OwnedOrganizationLimitException;

it('explica que por ahora solo se puede tener un negocio', function (): void {
    expect(OwnedOrganizationLimitException::reached(1)->getMessage())
        ->toBe('Ya tienes un negocio propio. Por ahora cada persona puede tener uno solo.');
});

it('nombra el máximo cuando es mayor que uno', function (): void {
    expect(OwnedOrganizationLimitException::reached(3)->getMessage())->toContain('3 negocios');
});

it('es una excepción de tiempo de ejecución', function (): void {
    expect(OwnedOrganizationLimitException::reached(1))->toBeInstanceOf(RuntimeException::class);
});
