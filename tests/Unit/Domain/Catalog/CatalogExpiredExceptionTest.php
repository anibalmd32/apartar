<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogExpiredException;
use App\Models\Catalog;
use App\Models\Organization;

/**
 * El `410` de `REQ-CAT-021` SP-3: la campaña existió y terminó.
 */
function expiredCatalog(): Catalog
{
    $catalog = (new Catalog)->forceFill(['name' => 'Ofertas de septiembre']);
    $catalog->setRelation('organization', (new Organization)->forceFill(['name' => 'Ferretería Oriente']));

    return $catalog;
}

it('responde 410 y no 404', function (): void {
    expect(CatalogExpiredException::STATUS)->toBe(410);
});

it('lleva la campaña y el negocio, y nada más', function (): void {
    $exception = CatalogExpiredException::of(expiredCatalog());

    expect($exception->toArray())->toBe([
        'catalogName' => 'Ofertas de septiembre',
        'businessName' => 'Ferretería Oriente',
    ]);
});

it('explica qué pasó en su mensaje', function (): void {
    expect(CatalogExpiredException::of(expiredCatalog())->getMessage())
        ->toBe('La campaña «Ofertas de septiembre» de Ferretería Oriente ya terminó.');
});
