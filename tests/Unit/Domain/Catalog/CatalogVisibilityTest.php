<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogVisibility;

it('declara los cuatro estados de los contratos compartidos', function (): void {
    $values = array_map(fn (CatalogVisibility $state): string => $state->value, CatalogVisibility::cases());

    expect($values)->toBe(['draft', 'listed', 'unlisted', 'expired']);
});

it('sirve por enlace tanto el listado como el no listado', function (CatalogVisibility $state, bool $accessible): void {
    expect($state->isPubliclyAccessible())->toBe($accessible);
})->with([
    'borrador' => [CatalogVisibility::Draft, false],
    'listado' => [CatalogVisibility::Listed, true],
    'no listado' => [CatalogVisibility::Unlisted, true],
    'expirado' => [CatalogVisibility::Expired, false],
]);

it('lista en la vitrina solo el catálogo listado', function (CatalogVisibility $state, bool $inStorefront): void {
    expect($state->appearsInStorefront())->toBe($inStorefront);
})->with([
    'borrador' => [CatalogVisibility::Draft, false],
    'listado' => [CatalogVisibility::Listed, true],
    'no listado' => [CatalogVisibility::Unlisted, false],
    'expirado' => [CatalogVisibility::Expired, false],
]);

it('no lista en la vitrina nada que no sea accesible', function (): void {
    $inconsistent = array_filter(
        CatalogVisibility::cases(),
        fn (CatalogVisibility $state): bool => $state->appearsInStorefront() && ! $state->isPubliclyAccessible(),
    );

    expect($inconsistent)->toBe([]);
});

it('consume cupo del plan solo lo publicado y vigente', function (): void {
    expect(array_values(array_filter(
        CatalogVisibility::cases(),
        fn (CatalogVisibility $state): bool => $state->countsTowardPlanLimit(),
    )))->toBe([CatalogVisibility::Listed, CatalogVisibility::Unlisted]);
});
