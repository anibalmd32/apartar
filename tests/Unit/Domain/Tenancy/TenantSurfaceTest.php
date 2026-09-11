<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantSurface;

/**
 * `TenantSurface` (`REQ-ORG-002` RB-4).
 *
 * El enum es la tabla de RB-4 en forma ejecutable. Lo que se comprueba es que
 * siguen estando las cinco superficies y que la plataforma sigue siendo la
 * única que opera sin tenant: añadir una superficie nueva sin decidir de dónde
 * saca su organización es exactamente el descuido que produce una consulta sin
 * filtro.
 */
it('declara las cinco superficies de RB-4', function (): void {
    $values = array_map(
        fn (TenantSurface $surface): string => $surface->value,
        TenantSurface::cases(),
    );

    expect($values)->toBe(['backoffice', 'public_catalog', 'storefront', 'portal', 'platform']);
});

it('exige tenant en todas las superficies salvo la de plataforma', function (): void {
    foreach (TenantSurface::cases() as $surface) {
        expect($surface->requiresTenant())->toBe($surface !== TenantSurface::Platform);
    }
});

it('resuelve cada superficie desde su valor', function (string $value, TenantSurface $expected): void {
    expect(TenantSurface::from($value))->toBe($expected);
})->with([
    ['backoffice', TenantSurface::Backoffice],
    ['public_catalog', TenantSurface::PublicCatalog],
    ['storefront', TenantSurface::Storefront],
    ['portal', TenantSurface::Portal],
    ['platform', TenantSurface::Platform],
]);
