<?php

declare(strict_types=1);

use App\Actions\Catalog\PublishCatalogData;
use Carbon\CarbonImmutable;

it('no declara la fecha de publicación, que está fuera de alcance', function (): void {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(PublishCatalogData::class))->getProperties(),
    );

    expect($properties)->toEqualCanonicalizing(['isListed', 'expiresAt']);
});

it('admite publicar sin caducidad', function (): void {
    $data = new PublishCatalogData(isListed: true, expiresAt: null);

    expect($data->isListed)->toBeTrue()->and($data->expiresAt)->toBeNull();
});

it('transporta la visibilidad y la fecha sin tocarlas', function (): void {
    $expiresAt = CarbonImmutable::parse('2026-09-30 23:59:59');

    $data = new PublishCatalogData(isListed: false, expiresAt: $expiresAt);

    expect($data->isListed)->toBeFalse()->and($data->expiresAt)->toEqual($expiresAt);
});
