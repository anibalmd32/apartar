<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogVisibility;

/**
 * El enum derivado de `REQ-CAT-022`.
 *
 * Aquí se fijan **los dos predicados**; la precedencia de los cuatro casos es
 * de `Catalog::visibility()` y se prueba contra el modelo, que es donde
 * `is_listed`, `published_at` y `expires_at` existen de verdad.
 */
it('declara los cuatro estados de los contratos compartidos', function (): void {
    $values = array_map(fn (CatalogVisibility $state): string => $state->value, CatalogVisibility::cases());

    expect($values)->toBe(['draft', 'listed', 'unlisted', 'expired']);
});

/**
 * RB-3 · `is_listed` **no controla el acceso**. Es la distinción que sostiene
 * el reparto de campañas por enlace, y confundirla convertiría «no aparece en
 * mi vitrina» en «es privado».
 */
it('sirve por enlace tanto el listado como el no listado', function (CatalogVisibility $state, bool $accessible): void {
    expect($state->isPubliclyAccessible())->toBe($accessible);
})->with([
    'borrador' => [CatalogVisibility::Draft, false],
    'listado' => [CatalogVisibility::Listed, true],
    'no listado' => [CatalogVisibility::Unlisted, true],
    'expirado' => [CatalogVisibility::Expired, false],
]);

/**
 * `REQ-ORG-006` RB-6 · la vitrina es solo para los listados. La asimetría con
 * el test de arriba **es** la función de `is_listed`.
 */
it('lista en la vitrina solo el catálogo listado', function (CatalogVisibility $state, bool $inStorefront): void {
    expect($state->appearsInStorefront())->toBe($inStorefront);
})->with([
    'borrador' => [CatalogVisibility::Draft, false],
    'listado' => [CatalogVisibility::Listed, true],
    'no listado' => [CatalogVisibility::Unlisted, false],
    'expirado' => [CatalogVisibility::Expired, false],
]);

/**
 * La implicación que no puede romperse en ninguna dirección: lo que está en la
 * vitrina es, por fuerza, accesible. Un estado que apareciera listado sin ser
 * alcanzable sería un enlace roto puesto ahí por el propio negocio.
 */
it('no lista en la vitrina nada que no sea accesible', function (): void {
    $inconsistent = array_filter(
        CatalogVisibility::cases(),
        fn (CatalogVisibility $state): bool => $state->appearsInStorefront() && ! $state->isPubliclyAccessible(),
    );

    expect($inconsistent)->toBe([]);
});

/**
 * `REQ-SUB-003` RB-2 · consumen el cupo `catalogs` los publicados, en la
 * vitrina o fuera; ni el borrador ni el caducado.
 */
it('consume cupo del plan solo lo publicado y vigente', function (): void {
    expect(array_values(array_filter(
        CatalogVisibility::cases(),
        fn (CatalogVisibility $state): bool => $state->countsTowardPlanLimit(),
    )))->toBe([CatalogVisibility::Listed, CatalogVisibility::Unlisted]);
});
