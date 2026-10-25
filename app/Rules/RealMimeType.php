<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

final class RealMimeType implements ValidationRule
{
    /**
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const RECEIPT_TYPES = self::ALLOWED + ['application/pdf' => ['pdf']];

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'image/jpeg' => 'JPG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
        'application/pdf' => 'PDF',
    ];

    /**
     * @param  array<string, list<string>>  $allowed
     */
    public function __construct(private readonly array $allowed = self::ALLOWED) {}

    public static function receipts(): self
    {
        return new self(self::RECEIPT_TYPES);
    }

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $mime = (string) $value->getMimeType();

        if (! array_key_exists($mime, $this->allowed)) {
            $fail(sprintf('El archivo no es %s. Comprueba que no sea otra cosa con el nombre cambiado.', $this->describeAllowed()));

            return;
        }

        $extension = mb_strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, $this->allowed[$mime], true)) {
            $fail(sprintf(
                'El archivo es %s pero su nombre termina en «.%s». Renómbralo para que coincidan.',
                $mime,
                $extension,
            ));
        }
    }

    private function describeAllowed(): string
    {
        $images = [];
        $documents = [];

        foreach (array_keys($this->allowed) as $mime) {
            $label = self::LABELS[$mime] ?? $mime;

            if (str_starts_with($mime, 'image/')) {
                $images[] = $label;
            } else {
                $documents[] = 'un '.$label;
            }
        }

        $parts = [];

        if ($images !== []) {
            $last = array_pop($images);
            $parts[] = 'una imagen '.($images === [] ? $last : implode(', ', $images).($documents === [] ? ' ni ' : ' o ').$last);
        }

        return implode(' ni ', [...$parts, ...$documents]);
    }
}
