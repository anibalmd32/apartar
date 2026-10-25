<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Catalog\PublicCatalogAccess;
use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @param  array<string, mixed>  $attributes
 */
function publicCatalog(array $attributes): Catalog
{
    $catalog = (new Catalog)->forceFill(['slug' => 'ofertas', 'name' => 'Ofertas', 'is_listed' => true, ...$attributes]);
    $catalog->setRelation('organization', (new Organization)->forceFill(['name' => 'Ferretería Oriente']));

    return $catalog;
}

it('abre lo publicado, esté o no en la vitrina', function (bool $listed): void {
    PublicCatalogAccess::ensureOpen(publicCatalog(['published_at' => now()->subDay(), 'is_listed' => $listed]));

    expect(true)->toBeTrue();
})->with(['en la vitrina' => [true], 'solo por enlace' => [false]]);

it('da 404 al borrador, como a lo que no existe', function (): void {
    PublicCatalogAccess::ensureOpen(publicCatalog(['published_at' => null]));
})->throws(ModelNotFoundException::class);

it('da 410 a lo que caducó', function (): void {
    PublicCatalogAccess::ensureOpen(publicCatalog(['published_at' => now()->subMonth(), 'expires_at' => now()->subDay()]));
})->throws(CatalogExpiredException::class);
