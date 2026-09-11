<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationStatus;

/**
 * `OrganizationStatus` (`REQ-ORG-008`).
 *
 * Corre sin framework (`REQ-QAX-003` RB-2). Lo que se fija aquí no es la
 * lógica —la matriz de capacidades es `T-009`— sino el **contrato de
 * persistencia**: el valor de cada caso es lo que queda escrito en la columna
 * `organizations.status`. Renombrar `'suspended'` no rompería ninguna llamada
 * en PHP, pero dejaría huérfanas todas las filas ya guardadas.
 */
it('declara exactamente los tres estados de REQ-ORG-008', function (): void {
    $values = array_map(
        fn (OrganizationStatus $status): string => $status->value,
        OrganizationStatus::cases(),
    );

    expect($values)->toBe(['active', 'suspended', 'closed']);
});

it('resuelve cada estado desde el valor que persiste la columna', function (string $value, OrganizationStatus $expected): void {
    expect(OrganizationStatus::from($value))->toBe($expected);
})->with([
    ['active', OrganizationStatus::Active],
    ['suspended', OrganizationStatus::Suspended],
    ['closed', OrganizationStatus::Closed],
]);

it('rechaza un valor que no corresponde a ningún estado', function (): void {
    expect(OrganizationStatus::tryFrom('deleted'))->toBeNull();
});
