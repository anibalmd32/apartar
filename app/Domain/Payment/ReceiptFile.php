<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Platform\PlatformSettingKey;
use App\Rules\RealMimeType;
use App\Support\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReceiptFile
{
    public const MAX_SIZE_MB = 5;

    public static function maxSizeMb(): int
    {
        return PlatformSettings::get(PlatformSettingKey::ReceiptMaxSizeMb);
    }

    public const MAX_PIXELS = 40_000_000;

    public const ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf';

    /**
     * @throws InvalidArgumentException
     */
    public static function fingerprint(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $hash = $path === false ? false : hash_file('sha256', $path);

        if ($hash === false) {
            throw new InvalidArgumentException(sprintf('No se pudo leer el archivo subido «%s».', $file->getClientOriginalName()));
        }

        return $hash;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function pathFor(int $organizationId, string $extension): string
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(sprintf('La organización %d no es un id: el comprobante no tiene dónde guardarse.', $organizationId));
        }

        $extension = mb_strtolower($extension);

        if (preg_match('/^[a-z0-9]{1,5}$/', $extension) !== 1) {
            throw new InvalidArgumentException(sprintf('«%s» no es una extensión de archivo.', $extension));
        }

        return sprintf('%d/%s.%s', $organizationId, Str::uuid()->toString(), $extension);
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    public static function typeOf(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $extensions = RealMimeType::RECEIPT_TYPES[$mime] ?? null;

        if ($extensions === null) {
            throw new InvalidArgumentException(sprintf(
                'El archivo es %s, que no es un tipo de comprobante. El formulario tenía que haberlo rechazado con RealMimeType::receipts().',
                $mime,
            ));
        }

        if ((int) $file->getSize() > self::maxSizeMb() * 1024 * 1024) {
            throw new InvalidArgumentException(sprintf(
                'El archivo pesa %d bytes y el máximo de un comprobante es de %d MB.',
                (int) $file->getSize(),
                self::maxSizeMb(),
            ));
        }

        return [$mime, $extensions[0]];
    }

    public static function publicIdOf(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }
}
