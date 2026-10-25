<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantSurface;

it('declara las cinco superficies', function (): void {
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
