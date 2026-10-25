<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationCapability;

it('declara las áreas de gobierno más la auditoría', function (): void {
    $names = array_map(
        fn (OrganizationCapability $capability): string => $capability->value,
        OrganizationCapability::ownerOnly(),
    );

    expect($names)->toBe(['subscription', 'billing', 'team', 'settings', 'payment_accounts', 'branding', 'audit']);
});

it('declara las seis áreas de operación diaria', function (): void {
    $names = array_map(
        fn (OrganizationCapability $capability): string => $capability->value,
        OrganizationCapability::shared(),
    );

    expect($names)->toBe(['products', 'catalogs', 'customers', 'collections', 'receipts', 'chat']);
});

it('reparte todas las capacidades entre las dos mitades', function (): void {
    expect(OrganizationCapability::cases())->toHaveCount(13);

    expect(array_merge(OrganizationCapability::ownerOnly(), OrganizationCapability::shared()))
        ->toHaveCount(13);
});

it('clasifica cada capacidad sin caer en un default permisivo', function (OrganizationCapability $capability): void {
    expect($capability->requiresOwner())->toBeBool();
})->with(OrganizationCapability::cases());

it('separa la cobranza a clientes de la facturación de la suscripción', function (): void {
    expect(OrganizationCapability::Collections->requiresOwner())->toBeFalse();
    expect(OrganizationCapability::Receipts->requiresOwner())->toBeFalse();
    expect(OrganizationCapability::Billing->requiresOwner())->toBeTrue();
});
