<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationStatus;

it('declara exactamente los tres estados', function (): void {
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

it('decide qué puede el equipo y qué puede el cliente en cada estado', function (OrganizationStatus $status, bool $team, bool $customer, bool $receipts): void {
    expect($status->allowsTeamAccess())->toBe($team)
        ->and($status->allowsCustomerAccess())->toBe($customer)
        ->and($status->acceptsCustomerReceipts())->toBe($receipts);
})->with([
    'activa' => [OrganizationStatus::Active, true, true, true],
    'suspendida' => [OrganizationStatus::Suspended, true, true, true],
    'cerrada' => [OrganizationStatus::Closed, false, true, false],
]);
