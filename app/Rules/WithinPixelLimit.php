<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Payment\ReceiptFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

final class WithinPixelLimit implements ValidationRule
{
    public const PHOTO_MAX_PIXELS = 60_000_000;

    public function __construct(
        public readonly int $maxPixels,
        private readonly string $message,
    ) {}

    public static function receipts(): self
    {
        return new self(ReceiptFile::MAX_PIXELS, 'La imagen es demasiado grande en píxeles. Sube una captura de pantalla normal.');
    }

    public static function photos(): self
    {
        return new self(
            self::PHOTO_MAX_PIXELS,
            sprintf(
                'La imagen pasa de %d megapíxeles. Tómala con la resolución normal de la cámara o redúcela antes de subirla.',
                intdiv(self::PHOTO_MAX_PIXELS, 1_000_000),
            ),
        );
    }

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $size = @getimagesize($value->getRealPath() ?: '');

        if ($size !== false && $this->maxPixels < $size[0] * $size[1]) {
            $fail($this->message);
        }
    }
}
