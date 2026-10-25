<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use InvalidArgumentException;

final readonly class StaffReason
{
    public const MIN = 10;

    public const MAX = 500;

    public string $text;

    public function __construct(string $text)
    {
        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN || $length > self::MAX) {
            throw new InvalidArgumentException(sprintf(
                'El motivo tiene que tener entre %d y %d caracteres, y tiene %d.',
                self::MIN,
                self::MAX,
                $length,
            ));
        }

        $this->text = $trimmed;
    }
}
