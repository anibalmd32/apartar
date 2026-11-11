<?php

declare(strict_types=1);

use App\Support\Media\ImageMetadata;
use Tests\Support\ImageUploads;

function decodes(string $bytes): bool
{
    return @imagecreatefromstring($bytes) instanceof GdImage;
}

/**
 * @return array<string, mixed>
 */
function exifOf(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'apartar-exif-');
    file_put_contents((string) $path, $bytes);
    $exif = @exif_read_data((string) $path);
    unlink((string) $path);

    return is_array($exif) ? $exif : [];
}

test('una foto JPEG pierde EXIF, GPS, XMP, IPTC, comentarios y lo que venga tras el final, y conserva la orientación', function (): void {
    $original = ImageUploads::jpegWithMetadata(6);
    $clean = ImageMetadata::strip($original);

    expect(exifOf($original))->toHaveKey('GPSLatitude');

    expect(ImageUploads::personalTraces($clean))->toBe([]);

    $exif = exifOf($clean);

    expect($exif['Orientation'] ?? null)->toBe(6)
        ->and(array_filter(array_keys($exif), fn (string $key): bool => str_starts_with($key, 'GPS') || $key === 'Make'))->toBe([])
        ->and(decodes($clean))->toBeTrue()
        ->and(getimagesizefromstring($clean))->toMatchArray([0 => 6, 1 => 4])
        ->and(str_ends_with($clean, "\xFF\xD9"))->toBeTrue();
});

test('un JPEG progresivo, con varios barridos, sigue siendo la misma imagen', function (): void {
    $image = imagecreatetruecolor(40, 30);
    imagefilledrectangle($image, 0, 0, 19, 29, (int) imagecolorallocate($image, 10, 120, 200));
    imageinterlace($image, true);
    ob_start();
    imagejpeg($image, null, 85);
    $progressive = (string) ob_get_clean();

    $clean = ImageMetadata::strip($progressive.'COLA-GPS');
    $decoded = imagecreatefromstring($clean);

    expect($decoded)->toBeInstanceOf(GdImage::class)
        ->and(str_contains($clean, 'COLA-GPS'))->toBeFalse()
        ->and(imagecolorat($decoded, 5, 5))->toBe(imagecolorat((function () use ($progressive): GdImage {
            $original = imagecreatefromstring($progressive);
            assert($original instanceof GdImage);

            return $original;
        })(), 5, 5));
});

test('sin rotación, el JPEG limpio no lleva ningún EXIF', function (): void {
    $clean = ImageMetadata::strip(ImageUploads::jpegWithMetadata(1));

    expect(str_contains($clean, "Exif\0\0"))->toBeFalse()
        ->and(decodes($clean))->toBeTrue();
});

test('un JPEG ya limpio no cambia al limpiarlo otra vez', function (): void {
    $clean = ImageMetadata::strip(ImageUploads::jpeg());

    expect(ImageMetadata::strip($clean))->toBe($clean)
        ->and(str_contains($clean, 'CREATOR'))->toBeFalse();
});

test('una imagen PNG pierde sus textos, su fecha y su EXIF, y conserva la orientación', function (): void {
    $clean = ImageMetadata::strip(ImageUploads::pngWithMetadata(6));

    expect(ImageUploads::personalTraces($clean))->toBe([]);

    expect(str_contains($clean, 'tIME'))->toBeFalse()
        ->and(str_contains($clean, 'eXIf'))->toBeTrue()
        ->and(decodes($clean))->toBeTrue()
        ->and(getimagesizefromstring($clean))->toMatchArray([0 => 6, 1 => 4]);
});

test('una imagen WebP pierde su EXIF y su XMP, y conserva la orientación con la cabecera al día', function (): void {
    $clean = ImageMetadata::strip(ImageUploads::webpWithMetadata(6));

    expect(ImageUploads::personalTraces($clean))->toBe([]);

    expect(substr($clean, 0, 4))->toBe('RIFF')
        ->and(unpack('V', substr($clean, 4, 4))[1])->toBe(strlen($clean) - 8)
        ->and(ord($clean[20]) & 0x0C)->toBe(0x08)
        ->and(decodes($clean))->toBeTrue();

    $unrotated = ImageMetadata::strip(ImageUploads::webpWithMetadata(1));

    expect(str_contains($unrotated, 'EXIF'))->toBeFalse()
        ->and(ord($unrotated[20]) & 0x0C)->toBe(0)
        ->and(decodes($unrotated))->toBeTrue();
});

test('el archivo se reescribe en su sitio solo si tenía algo que quitar', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'apartar-strip-');
    file_put_contents($path, ImageUploads::jpegWithMetadata());

    expect(ImageMetadata::stripFile($path))->toBeTrue()
        ->and(str_contains((string) file_get_contents($path), 'PhoneCo'))->toBeFalse()
        ->and(filesize($path))->toBe(strlen((string) file_get_contents($path)))
        ->and(ImageMetadata::stripFile($path))->toBeFalse();

    unlink($path);
});

test('rechaza lo que no es JPEG, PNG ni WebP y lo que viene dañado', function (string $bytes): void {
    ImageMetadata::strip($bytes);
})->with([
    'un PDF' => ["%PDF-1.4\n"],
    'un JPEG cortado' => ["\xFF\xD8\xFF\xE1\x40\x00Exif"],
    'un PNG cortado' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR"],
])->throws(InvalidArgumentException::class);
