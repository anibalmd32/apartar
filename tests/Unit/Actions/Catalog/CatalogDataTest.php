<?php

declare(strict_types=1);

use App\Actions\Catalog\CatalogData;
use Illuminate\Http\UploadedFile;

it('lleva los cuatro campos del CRUD y ninguno de publicación', function (): void {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(CatalogData::class))->getProperties(),
    );

    expect($properties)->toEqualCanonicalizing(['name', 'slug', 'description', 'cover']);
});

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
