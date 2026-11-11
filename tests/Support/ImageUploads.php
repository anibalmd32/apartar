<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

final class ImageUploads
{
    public static function real(string $name, string $contents): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-upload-', true).'-'.$name;

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('No se pudo escribir el archivo de prueba %s.', $path));
        }

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    public static function png(int $width = 4, int $height = 4): string
    {
        return self::encode($width, $height, fn (\GdImage $image): bool => imagepng($image));
    }

    public static function jpeg(int $width = 4, int $height = 4): string
    {
        return self::encode($width, $height, fn (\GdImage $image): bool => imagejpeg($image, null, 90));
    }

    public static function webp(int $width = 4, int $height = 4): string
    {
        return self::encode($width, $height, fn (\GdImage $image): bool => imagewebp($image, null, 90));
    }

    public static function pngHeader(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1A\n".$chunk('IHDR', $header).$chunk('IDAT', (string) gzcompress('')).$chunk('IEND', '');
    }

    /**
     * @return list<string>
     */
    public static function personalTraces(string $bytes): array
    {
        return array_values(array_filter(
            ['PhoneCo', 'GPS', 'xmpmeta', 'IPTC', 'Comentario secreto', 'Caracas', 'MOTIONVIDEO'],
            fn (string $trace): bool => str_contains($bytes, $trace),
        ));
    }

    public static function exif(int $orientation = 6): string
    {
        $entry = fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

        $ifd0 = pack('v', 3)
            .$entry(0x010F, 2, 8, pack('V', 50))
            .$entry(0x0112, 3, 1, pack('v', $orientation))
            .$entry(0x8825, 4, 1, pack('V', 58))
            .pack('V', 0);

        $gps = pack('v', 2)
            .$entry(0x0001, 2, 2, "N\0")
            .$entry(0x0002, 5, 3, pack('V', 88))
            .pack('V', 0);

        return "II\x2A\0".pack('V', 8).$ifd0."PhoneCo\0".$gps.pack('V*', 10, 1, 30, 1, 1234, 100);
    }

    public static function jpegWithMetadata(int $orientation = 6): string
    {
        $jpeg = self::jpeg(6, 4);
        $app0 = 4 + unpack('n', substr($jpeg, 4, 2))[1];
        $segment = fn (int $marker, string $payload): string => "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, $app0)
            .$segment(0xE1, "Exif\0\0".self::exif($orientation))
            .$segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>GPSLatitude 10.5</x:xmpmeta>")
            .$segment(0xED, "Photoshop 3.0\0IPTC-Caracas")
            .$segment(0xFE, 'Comentario secreto')
            .substr($jpeg, $app0)
            .'MOTIONVIDEO-GPS';
    }

    public static function pngWithMetadata(int $orientation = 6): string
    {
        $png = self::png(6, 4);
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $afterHeader = 8 + 25;

        return substr($png, 0, $afterHeader)
            .$chunk('eXIf', self::exif($orientation))
            .$chunk('tEXt', "Location\0Caracas 10.5,-66.9")
            .$chunk('tIME', pack('nCCCCC', 2026, 11, 11, 10, 0, 0))
            .substr($png, $afterHeader);
    }

    public static function webpWithMetadata(int $orientation = 6): string
    {
        $simple = substr(self::webp(6, 4), 12);
        $chunk = fn (string $fourcc, string $data): string => $fourcc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\0" : '');
        $canvas = substr(pack('V', 5), 0, 3).substr(pack('V', 3), 0, 3);

        $body = 'WEBP'
            .$chunk('VP8X', chr(0x08 | 0x04)."\0\0\0".$canvas)
            .$simple
            .$chunk('EXIF', self::exif($orientation))
            .$chunk('XMP ', '<x:xmpmeta>GPSLatitude 10.5</x:xmpmeta>');

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    /**
     * @param  callable(\GdImage): bool  $writer
     */
    private static function encode(int $width, int $height, callable $writer): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD no pudo crear la imagen de prueba.');
        }

        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height - 1, (int) imagecolorallocate($image, 200, 30, 30));

        ob_start();
        $writer($image);

        return (string) ob_get_clean();
    }
}
