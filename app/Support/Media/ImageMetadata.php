<?php

declare(strict_types=1);

namespace App\Support\Media;

use InvalidArgumentException;
use RuntimeException;

final class ImageMetadata
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    private const EXIF_PREFIX = "Exif\0\0";

    private const ORIENTATION_TAG = 0x0112;

    /**
     * @var array<int, string>
     */
    private const JPEG_KEPT_APP_SEGMENTS = [0xE0 => "JFIF\0", 0xE2 => "ICC_PROFILE\0", 0xEE => 'Adobe'];

    /**
     * @var list<string>
     */
    private const PNG_DROPPED_CHUNKS = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME', 'dSIG'];

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function stripFile(string $path): bool
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException(sprintf('No se pudo leer la imagen %s para quitarle los metadatos.', $path));
        }

        $clean = self::strip($bytes);

        if ($clean === $bytes) {
            return false;
        }

        if (file_put_contents($path, $clean) === false) {
            throw new RuntimeException(sprintf('No se pudo reescribir la imagen %s sin metadatos.', $path));
        }

        clearstatcache(true, $path);

        return true;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function strip(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8") => self::jpeg($bytes),
            str_starts_with($bytes, self::PNG_SIGNATURE) => self::png($bytes),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::webp($bytes),
            default => throw new InvalidArgumentException('La imagen no es JPEG, PNG ni WebP: no se le pueden quitar los metadatos.'),
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function jpeg(string $bytes): string
    {
        $length = strlen($bytes);
        $out = "\xFF\xD8";
        $exifAt = 2;
        $offset = 2;
        $orientation = null;

        while ($offset < $length) {
            if ($bytes[$offset] !== "\xFF" || $offset + 1 >= $length) {
                throw self::malformed('JPEG');
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            if ($marker === 0xD9) {
                return self::withJpegOrientation($out."\xFF\xD9", $exifAt, $orientation);
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($bytes, $offset, 2);
                $offset += 2;

                continue;
            }

            $size = self::uint16($bytes, $offset + 2, 'n');

            if ($size < 2 || $offset + 2 + $size > $length) {
                throw self::malformed('JPEG');
            }

            $segment = substr($bytes, $offset, $size + 2);
            $payload = substr($segment, 4);
            $offset += $size + 2;

            if ($marker === 0xE1 && str_starts_with($payload, self::EXIF_PREFIX)) {
                $orientation ??= self::orientation(substr($payload, strlen(self::EXIF_PREFIX)));
            }

            if (! self::keepsJpegSegment($marker, $payload)) {
                continue;
            }

            $out .= $segment;

            if ($marker === 0xE0 && $exifAt === 2) {
                $exifAt = strlen($out);
            }

            if ($marker === 0xDA) {
                $end = self::endOfScan($bytes, $offset);
                $out .= substr($bytes, $offset, $end - $offset);
                $offset = $end;
            }
        }

        return self::withJpegOrientation($out, $exifAt, $orientation);
    }

    private static function keepsJpegSegment(int $marker, string $payload): bool
    {
        if ($marker === 0xFE) {
            return false;
        }

        if ($marker < 0xE0 || $marker > 0xEF) {
            return true;
        }

        $signature = self::JPEG_KEPT_APP_SEGMENTS[$marker] ?? null;

        return $signature !== null && str_starts_with($payload, $signature);
    }

    private static function endOfScan(string $bytes, int $offset): int
    {
        $length = strlen($bytes);

        while (($position = strpos($bytes, "\xFF", $offset)) !== false && $position + 1 < $length) {
            $next = ord($bytes[$position + 1]);

            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $offset = $position + 2;

                continue;
            }

            if ($next === 0xFF) {
                $offset = $position + 1;

                continue;
            }

            return $position;
        }

        return $length;
    }

    private static function withJpegOrientation(string $jpeg, int $at, ?int $orientation): string
    {
        if ($orientation === null || $orientation === 1) {
            return $jpeg;
        }

        $payload = self::EXIF_PREFIX.self::minimalExif($orientation);
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, $at).$segment.substr($jpeg, $at);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function png(string $bytes): string
    {
        $length = strlen($bytes);
        $out = self::PNG_SIGNATURE;
        $offset = strlen(self::PNG_SIGNATURE);

        while ($offset < $length) {
            $size = self::uint32($bytes, $offset, 'N');

            if ($offset + 12 + $size > $length) {
                throw self::malformed('PNG');
            }

            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $size);
            $chunk = substr($bytes, $offset, $size + 12);
            $offset += $size + 12;

            if ($type === 'eXIf') {
                $orientation = self::orientation($data);

                if ($orientation !== null && $orientation !== 1) {
                    $exif = self::minimalExif($orientation);
                    $out .= pack('N', strlen($exif)).'eXIf'.$exif.pack('N', crc32('eXIf'.$exif));
                }

                continue;
            }

            if (in_array($type, self::PNG_DROPPED_CHUNKS, true)) {
                continue;
            }

            $out .= $chunk;

            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function webp(string $bytes): string
    {
        $length = strlen($bytes);
        $offset = 12;
        $orientation = null;
        $chunks = [];

        while ($offset + 8 <= $length) {
            $fourcc = substr($bytes, $offset, 4);
            $size = self::uint32($bytes, $offset + 4, 'V');

            if ($offset + 8 + $size > $length) {
                throw self::malformed('WebP');
            }

            $data = substr($bytes, $offset + 8, $size);
            $offset += 8 + $size + ($size % 2);

            if ($fourcc === 'EXIF') {
                $orientation = self::orientation(str_starts_with($data, self::EXIF_PREFIX) ? substr($data, strlen(self::EXIF_PREFIX)) : $data);

                continue;
            }

            if ($fourcc !== 'XMP ') {
                $chunks[] = [$fourcc, $data];
            }
        }

        if (($chunks[0][0] ?? null) === 'VP8X' && $chunks[0][1] !== '') {
            $keepsExif = $orientation !== null && $orientation !== 1;
            $flags = (ord($chunks[0][1][0]) & ~0x0C) | ($keepsExif ? 0x08 : 0);
            $chunks[0][1] = chr($flags).substr($chunks[0][1], 1);

            if ($keepsExif) {
                $chunks[] = ['EXIF', self::minimalExif((int) $orientation)];
            }
        }

        $body = 'WEBP';

        foreach ($chunks as [$fourcc, $data]) {
            $body .= $fourcc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\0" : '');
        }

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    private static function orientation(string $tiff): ?int
    {
        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }

        [$short, $long] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];

        try {
            $ifd = self::uint32($tiff, 4, $long);
            $entries = self::uint16($tiff, $ifd, $short);

            for ($index = 0; $index < $entries; $index++) {
                $entry = $ifd + 2 + 12 * $index;

                if (self::uint16($tiff, $entry, $short) === self::ORIENTATION_TAG) {
                    $value = self::uint16($tiff, $entry + 8, $short);

                    return $value >= 1 && $value <= 8 ? $value : null;
                }
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return null;
    }

    private static function minimalExif(int $orientation): string
    {
        return "MM\0\x2A".pack('N', 8).pack('n', 1).pack('nnNn', self::ORIENTATION_TAG, 3, 1, $orientation)."\0\0".pack('N', 0);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function uint16(string $bytes, int $offset, string $format): int
    {
        return self::unpack($bytes, $offset, 2, $format);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function uint32(string $bytes, int $offset, string $format): int
    {
        return self::unpack($bytes, $offset, 4, $format);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function unpack(string $bytes, int $offset, int $width, string $format): int
    {
        if ($offset < 0 || $offset + $width > strlen($bytes)) {
            throw new InvalidArgumentException('La imagen se acaba antes de lo que dicen sus cabeceras.');
        }

        $value = unpack($format, substr($bytes, $offset, $width));

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    private static function malformed(string $format): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('La imagen dice ser %s pero su estructura está dañada: no se le pueden quitar los metadatos.', $format));
    }
}
