<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;

/**
 * La matriz de `REQ-ORG-003` RB-1: dos roles contra las doce áreas.
 *
 * Un caso por celda y las celdas se derivan del enum, no de una lista escrita
 * a mano: añadir un área de gobierno añade sus dos casos solo, y si el reparto
 * no se decide, el `match` de `requiresOwner()` lanza. Es el mismo principio
 * que la suite de fuga de `REQ-QAX-008` SP-1 —lo que no se sabe clasificar
 * falla— aplicado a los permisos.
 */
it('declara exactamente los dos roles de D-4', function (): void {
    $values = array_map(fn (OrganizationRole $role): string => $role->value, OrganizationRole::cases());

    expect($values)->toBe(['owner', 'member']);
});

/**
 * Las seis celdas del `owner` sobre el gobierno del negocio.
 */
it('deja al owner gobernar las seis áreas de gobierno', function (OrganizationCapability $capability): void {
    expect(OrganizationRole::Owner->can($capability))->toBeTrue();
})->with(OrganizationCapability::ownerOnly());

/**
 * Las seis del `member`, que son las que dan sentido al gate: un empleado que
 * llega a la facturación no está delegando, está sustituyendo al dueño.
 */
it('niega al member las seis áreas de gobierno', function (OrganizationCapability $capability): void {
    expect(OrganizationRole::Member->can($capability))->toBeFalse();
})->with(OrganizationCapability::ownerOnly());

/**
 * Y las doce celdas de la operación diaria: aquí los dos roles empatan, que es
 * justo lo que hace útil al `member`.
 */
it('deja a los dos roles operar el día a día', function (OrganizationCapability $capability): void {
    expect(OrganizationRole::Owner->can($capability))->toBeTrue();
    expect(OrganizationRole::Member->can($capability))->toBeTrue();
})->with(OrganizationCapability::shared());

/**
 * Los tres métodos con nombre del contrato del módulo, que son la forma en que
 * el resto del código pregunta sin tener que conocer las doce áreas.
 */
it('resume el gobierno en los tres métodos del contrato', function (): void {
    expect(OrganizationRole::Owner->canManageBilling())->toBeTrue();
    expect(OrganizationRole::Owner->canManageTeam())->toBeTrue();
    expect(OrganizationRole::Owner->canManageSettings())->toBeTrue();

    expect(OrganizationRole::Member->canManageBilling())->toBeFalse();
    expect(OrganizationRole::Member->canManageTeam())->toBeFalse();
    expect(OrganizationRole::Member->canManageSettings())->toBeFalse();
});

/**
 * Y los tres métodos no son una tabla aparte: se responden desde las mismas
 * capacidades, así que no pueden contradecir a la matriz.
 */
it('responde los tres métodos desde las capacidades y no desde una copia', function (): void {
    expect(OrganizationRole::Owner->canManageBilling())
        ->toBe(OrganizationRole::Owner->can(OrganizationCapability::Billing));

    expect(OrganizationRole::Member->canManageSettings())
        ->toBe(OrganizationRole::Member->can(OrganizationCapability::PaymentAccounts));
});

/**
 * Lo que el menú necesita para no ofrecer lo que va a dar `403` (RB-4). Que la
 * lista sea correcta no sustituye a la policy: son las dos protecciones, y
 * esta es la de cortesía.
 */
it('enumera las capacidades de cada rol para la navegación', function (): void {
    expect(OrganizationRole::Owner->capabilities())->toHaveCount(12);
    expect(OrganizationRole::Member->capabilities())->toHaveCount(6);

    $memberAreas = array_map(
        fn (OrganizationCapability $capability): string => $capability->value,
        OrganizationRole::Member->capabilities(),
    );

    expect($memberAreas)->toBe(['products', 'catalogs', 'customers', 'collections', 'receipts', 'chat']);
});
