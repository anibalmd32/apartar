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
