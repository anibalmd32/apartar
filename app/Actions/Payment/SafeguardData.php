<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Safeguard\SafeguardDestination;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final readonly class SafeguardData
{
    public const REFERENCE_MAX = 120;

    public const NOTE_MAX = 500;

    public ?string $reference;

    public ?string $note;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public SafeguardDestination $destination,
        ?string $reference = null,
        public ?UploadedFile $proof = null,
        ?string $note = null,
        public bool $removeProof = false,
    ) {
        $this->reference = self::trimmed($reference, self::REFERENCE_MAX, 'La referencia del resguardo');
        $this->note = self::trimmed($note, self::NOTE_MAX, 'La nota del resguardo');
    }

    private static function trimmed(?string $value, int $max, string $what): ?string
    {
        $trimmed = trim($value ?? '');

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException(sprintf('%s tiene como máximo %d caracteres.', $what, $max));
        }

        return $trimmed === '' ? null : $trimmed;
    }
}
