<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationBrand;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

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
        ->toBe(['name', 'slug', 'logoUrl', 'brandColor', 'brandingEnabled', 'tagline']);
});

it('no inventa una url cuando no hay logo', function (?string $path): void {
    Storage::fake(OrganizationBrand::LOGO_DISK);

    $organization = new Organization;
    $organization->forceFill(['name' => 'Tienda', 'slug' => 'tienda', 'logo_path' => $path, 'brand_color' => null]);

    expect(OrganizationBrand::of($organization, brandingEnabled: true)->logoUrl)->toBeNull();
})->with([
    'nulo' => [null],
    'vacío' => [''],
]);
