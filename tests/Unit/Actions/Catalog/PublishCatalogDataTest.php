<?php

declare(strict_types=1);

use App\Actions\Catalog\PublishCatalogData;
use Carbon\CarbonImmutable;

/**
 * Con qué se publica un catálogo (`REQ-CAT-022` RB-3, RB-6).
 *
 * Dos campos, y lo que merece fijarse es el que **no** está: `published_at`.
 * `REQ-CAT-022` deja la publicación programada fuera de alcance —«solo se
 * programa la expiración»— y quien fija la fecha es la acción, al publicar.
 * Un campo aquí sería la puerta por la que esa función entraría sin que nadie
 * la hubiera decidido, y produciría además el estado que
 * `Catalog::visibility()` describe como imposible: un `published_at` futuro
 * que nadie sabe leer como «programado».
 */
it('no declara la fecha de publicación, que está fuera de alcance', function (): void {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(PublishCatalogData::class))->getProperties(),
    );

    expect($properties)->toEqualCanonicalizing(['isListed', 'expiresAt']);
});

/**
 * RB-6 · la caducidad es opcional: sin ella el catálogo no caduca.
 */
it('admite publicar sin caducidad', function (): void {
    $data = new PublishCatalogData(isListed: true, expiresAt: null);

    expect($data->isListed)->toBeTrue()->and($data->expiresAt)->toBeNull();
});

/**
 * RB-3 · fuera de la vitrina sigue siendo publicado: `is_listed` no controla
 * el acceso, y el objeto lo transporta tal cual sin decidir nada.
 */
it('transporta la visibilidad y la fecha sin tocarlas', function (): void {
    $expiresAt = CarbonImmutable::parse('2026-09-30 23:59:59');

    $data = new PublishCatalogData(isListed: false, expiresAt: $expiresAt);

    expect($data->isListed)->toBeFalse()->and($data->expiresAt)->toEqual($expiresAt);
});
