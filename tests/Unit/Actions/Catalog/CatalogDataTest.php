<?php

declare(strict_types=1);

use App\Actions\Catalog\CatalogData;
use Illuminate\Http\UploadedFile;

/**
 * El objeto de datos del formulario de catálogo (`REQ-BOF-024` RB-1).
 *
 * Es un portador sin comportamiento, así que lo que merece fijarse es **lo que
 * declara y lo que no**. Los cuatro campos que lleva son los del CRUD; los
 * tres de publicación —`is_listed`, `published_at` y `expires_at`— no están, y
 * esa ausencia es la que sostiene que `PublishCatalogAction` sea el único
 * escritor de esas columnas. El día que alguien los añada aquí, este test se
 * cae antes de que el segundo camino exista.
 */
it('lleva los cuatro campos del CRUD y ninguno de publicación', function (): void {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(CatalogData::class))->getProperties(),
    );

    expect($properties)->toEqualCanonicalizing(['name', 'slug', 'description', 'cover']);
});

/**
 * `null` en `slug` significa «derívalo del nombre» al crear y «no lo toques»
 * al editar, y en `cover` «deja la portada que haya». Los tres opcionales
 * llegan nulos sin que el objeto invente nada.
 */
it('admite el enlace, la descripción y la portada vacíos', function (): void {
    $data = new CatalogData(name: 'Ofertas de septiembre', slug: null, description: null, cover: null);

    expect($data->name)->toBe('Ofertas de septiembre')
        ->and($data->slug)->toBeNull()
        ->and($data->description)->toBeNull()
        ->and($data->cover)->toBeNull();
});

it('conserva el enlace escrito a mano y la portada nueva', function (): void {
    $cover = UploadedFile::fake()->image('portada.jpg');

    $data = new CatalogData(
        name: 'Ofertas de septiembre',
        slug: 'ofertas-septiembre',
        description: 'Lo que queda de temporada.',
        cover: $cover,
    );

    expect($data->slug)->toBe('ofertas-septiembre')
        ->and($data->description)->toBe('Lo que queda de temporada.')
        ->and($data->cover)->toBe($cover);
});
