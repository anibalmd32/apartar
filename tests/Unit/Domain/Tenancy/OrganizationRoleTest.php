<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;

it('declara exactamente los dos roles de D-4', function (): void {
    $values = array_map(fn (OrganizationRole $role): string => $role->value, OrganizationRole::cases());

    expect($values)->toBe(['owner', 'member']);
});

it('deja al owner gobernar las seis áreas de gobierno', function (OrganizationCapability $capability): void {
    expect(OrganizationRole::Owner->can($capability))->toBeTrue();
})->with(OrganizationCapability::ownerOnly());

it('niega al member las seis áreas de gobierno', function (OrganizationCapability $capability): void {
    expect(OrganizationRole::Member->can($capability))->toBeFalse();
})->with(OrganizationCapability::ownerOnly());

it('deja a los dos roles operar el día a día', function (OrganizationCapability $capability): void {
    expect(OrganizationRole::Owner->can($capability))->toBeTrue();
    expect(OrganizationRole::Member->can($capability))->toBeTrue();
})->with(OrganizationCapability::shared());

it('resume el gobierno en los tres métodos del contrato', function (): void {
    expect(OrganizationRole::Owner->canManageBilling())->toBeTrue();
    expect(OrganizationRole::Owner->canManageTeam())->toBeTrue();
    expect(OrganizationRole::Owner->canManageSettings())->toBeTrue();

    expect(OrganizationRole::Member->canManageBilling())->toBeFalse();
    expect(OrganizationRole::Member->canManageTeam())->toBeFalse();
    expect(OrganizationRole::Member->canManageSettings())->toBeFalse();
});

it('responde los tres métodos desde las capacidades y no desde una copia', function (): void {
    expect(OrganizationRole::Owner->canManageBilling())
        ->toBe(OrganizationRole::Owner->can(OrganizationCapability::Billing));

    expect(OrganizationRole::Member->canManageSettings())
        ->toBe(OrganizationRole::Member->can(OrganizationCapability::PaymentAccounts));
});

it('enumera las capacidades de cada rol para la navegación', function (): void {
    expect(OrganizationRole::Owner->capabilities())->toHaveCount(13);
    expect(OrganizationRole::Member->capabilities())->toHaveCount(6);

    $memberAreas = array_map(
        fn (OrganizationCapability $capability): string => $capability->value,
        OrganizationRole::Member->capabilities(),
    );

    expect($memberAreas)->toBe(['products', 'catalogs', 'customers', 'collections', 'receipts', 'chat']);
});
