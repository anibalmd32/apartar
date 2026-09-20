<?php

declare(strict_types=1);

use App\Support\Media\TenantPathGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * La ruta física de un archivo (`REQ-BOF-022` RB-9, `REQ-CAT-005` RB-7).
 *
 * Es el **único** sitio del proyecto donde el aislamiento no puede apoyarse en
 * el *global scope*: un fichero en disco no se consulta, se sirve por su ruta.
 * De ahí que lo que se prueba aquí sea, sobre todo, que sin declarar la
 * organización **no hay ruta**.
 */
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

/**
 * Y dos negocios **no comparten carpeta**, que es lo único que este generador
 * tiene que garantizar de verdad.
 */
it('separa por completo los archivos de dos organizaciones', function (): void {
    $generator = new TenantPathGenerator;

    $mine = $generator->getPath(mediaWith(1, id: 10));
    $theirs = $generator->getPath(mediaWith(2, id: 11));

    expect($mine)->not->toBe($theirs);
    expect($mine)->toStartWith('organizations/1/');
    expect($theirs)->toStartWith('organizations/2/');
});

/**
 * **Falla cerrado.** Un medio que no declara de quién es no cae a un
 * directorio compartido: revienta. Es la convención del resto del proyecto, y
 * aquí lo que evita es que la próxima colección de medios se cuele sin
 * aislamiento físico y nadie se entere hasta que haya que auditar el disco.
 */
it('lanza cuando el medio no declara su organización', function (): void {
    (new TenantPathGenerator)->getPath(mediaWith(null));
})->throws(RuntimeException::class, 'no declara su organización');

it('lanza también al componer la ruta de las conversiones', function (): void {
    (new TenantPathGenerator)->getPathForConversions(mediaWith(null));
})->throws(RuntimeException::class);
