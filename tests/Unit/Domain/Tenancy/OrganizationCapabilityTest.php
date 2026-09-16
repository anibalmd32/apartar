<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationCapability;

/**
 * El reparto de `REQ-ORG-003` RB-1 en forma de enum.
 *
 * Lo que se fija aquí es la **frontera**, no el número: que las seis áreas de
 * gobierno sean exactamente esas seis y que las seis de operación diaria sigan
 * siendo del `member`. La mitad que se erosiona con el tiempo es siempre la
 * segunda —cada pantalla nueva parece un poco delicada— y el día que el
 * `member` deje de poder cobrar, el rol habrá dejado de servir para delegar.
 */
it('declara las áreas de gobierno de RB-1 más la auditoría', function (): void {
    $names = array_map(
        fn (OrganizationCapability $capability): string => $capability->value,
        OrganizationCapability::ownerOnly(),
    );

    expect($names)->toBe(['subscription', 'billing', 'team', 'settings', 'payment_accounts', 'branding', 'audit']);
});

it('declara las seis áreas de operación diaria de RB-1', function (): void {
    $names = array_map(
        fn (OrganizationCapability $capability): string => $capability->value,
        OrganizationCapability::shared(),
    );

    expect($names)->toBe(['products', 'catalogs', 'customers', 'collections', 'receipts', 'chat']);
});

/**
 * Ninguna capacidad suelta: toda área cae de un lado o del otro.
 *
 * El número sube a trece con `audit` (`T-016`), que es una séptima de gobierno:
 * `REQ-BOF-005` RB-9 reserva el registro al `owner` y la frontera de RB-1 se
 * declara en un solo sitio, así que un área nueva pasa por aquí o el menú
 * tendría que volver a decidirla por su cuenta.
 */
it('reparte todas las capacidades entre las dos mitades', function (): void {
    expect(OrganizationCapability::cases())->toHaveCount(13);

    expect(array_merge(OrganizationCapability::ownerOnly(), OrganizationCapability::shared()))
        ->toHaveCount(13);
});

it('clasifica cada capacidad sin caer en un default permisivo', function (OrganizationCapability $capability): void {
    // `requiresOwner()` es un `match` exhaustivo: si alguien añade un área y
    // no decide de qué lado cae, esto lanza `UnhandledMatchError` en vez de
    // devolver `false` por descuido, que es el lado que abre permisos.
    expect($capability->requiresOwner())->toBeBool();
})->with(OrganizationCapability::cases());

/**
 * La cobranza es del `member` y la facturación no, y esa distinción es la que
 * más fácilmente se confunde: una es el dinero que los clientes le deben al
 * negocio —el trabajo diario del empleado— y la otra es lo que el negocio le
 * paga a la plataforma.
 */
it('separa la cobranza a clientes de la facturación de la suscripción', function (): void {
    expect(OrganizationCapability::Collections->requiresOwner())->toBeFalse();
    expect(OrganizationCapability::Receipts->requiresOwner())->toBeFalse();
    expect(OrganizationCapability::Billing->requiresOwner())->toBeTrue();
});
