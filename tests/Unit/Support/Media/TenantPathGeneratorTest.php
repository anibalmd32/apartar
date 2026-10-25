<?php

declare(strict_types=1);

use App\Support\Media\TenantPathGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

function mediaWith(?int $organizationId, int $id = 7): Media
{
    $media = new Media;

    $media->forceFill([
        'id' => $id,
        'custom_properties' => $organizationId === null
            ? []
            : [TenantPathGenerator::ORGANIZATION_PROPERTY => $organizationId],
    ]);

    return $media;
}

it('mete la organización delante de la carpeta del archivo', function (): void {
    expect((new TenantPathGenerator)->getPath(mediaWith(42)))->toBe('organizations/42/media/7/');
});

it('cuelga las conversiones y las responsivas de la misma carpeta', function (): void {
    $generator = new TenantPathGenerator;
    $media = mediaWith(42);

    expect($generator->getPathForConversions($media))->toBe('organizations/42/media/7/conversions/');
    expect($generator->getPathForResponsiveImages($media))->toBe('organizations/42/media/7/responsive-images/');
});

it('separa por completo los archivos de dos organizaciones', function (): void {
    $generator = new TenantPathGenerator;

    $mine = $generator->getPath(mediaWith(1, id: 10));
    $theirs = $generator->getPath(mediaWith(2, id: 11));

    expect($mine)->not->toBe($theirs);
    expect($mine)->toStartWith('organizations/1/');
    expect($theirs)->toStartWith('organizations/2/');
});

it('lanza cuando el medio no declara su organización', function (): void {
    (new TenantPathGenerator)->getPath(mediaWith(null));
})->throws(RuntimeException::class, 'no declara su organización');

it('lanza también al componer la ruta de las conversiones', function (): void {
    (new TenantPathGenerator)->getPathForConversions(mediaWith(null));
})->throws(RuntimeException::class);
