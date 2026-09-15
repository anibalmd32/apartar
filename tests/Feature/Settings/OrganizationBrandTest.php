<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationBrand;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * Cómo se presenta un negocio (`REQ-ORG-006` RB-5 y RB-5b).
 *
 * Va en `Feature` y no en `Unit` porque la URL del logo la resuelve el disco
 * configurado, y comprobar que *se llama* a `Storage` no es comprobar que sale
 * una URL.
 *
 * Lo que se fija aquí es la separación que sostiene SP-3: **el logo y el color
 * viajan aunque el plan no los aplique**. La pantalla de ajustes tiene que
 * enseñar lo que hay guardado, sobre todo cuando no se está aplicando: quien
 * está en Free tiene que **ver** lo que se pierde (`REQ-SUB-004` RB-7).
 */
it('viaja con el logo y el color aunque el plan no los aplique', function (): void {
    Storage::fake(OrganizationBrand::LOGO_DISK);

    $organization = new Organization;
    $organization->forceFill([
        'name' => 'Ferretería El Tornillo',
        'slug' => 'ferreteria-el-tornillo',
        'logo_path' => 'logos/1/logo.png',
        'brand_color' => '#1f6feb',
    ]);

    $brand = OrganizationBrand::of($organization, brandingEnabled: false);

    expect($brand->brandingEnabled)->toBeFalse();
    expect($brand->brandColor)->toBe('#1f6feb');
    expect($brand->logoUrl)->toContain('logos/1/logo.png');
});

it('entrega las cinco claves del contrato del módulo', function (): void {
    Storage::fake(OrganizationBrand::LOGO_DISK);

    $organization = new Organization;
    $organization->forceFill(['name' => 'Tienda', 'slug' => 'tienda', 'logo_path' => null, 'brand_color' => null]);

    expect(array_keys(OrganizationBrand::of($organization, brandingEnabled: true)->toArray()))
        ->toBe(['name', 'slug', 'logoUrl', 'brandColor', 'brandingEnabled']);
});

/**
 * Sin logo no hay URL, y `null` es la respuesta: una cadena vacía obligaría a
 * que cada pantalla distinguiera dos formas del mismo «no hay».
 */
it('no inventa una url cuando no hay logo', function (?string $path): void {
    Storage::fake(OrganizationBrand::LOGO_DISK);

    $organization = new Organization;
    $organization->forceFill(['name' => 'Tienda', 'slug' => 'tienda', 'logo_path' => $path, 'brand_color' => null]);

    expect(OrganizationBrand::of($organization, brandingEnabled: true)->logoUrl)->toBeNull();
})->with([
    'nulo' => [null],
    'vacío' => [''],
]);
