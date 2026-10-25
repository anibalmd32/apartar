<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class CannotDisableSelfException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'No puedes desactivar tu propia cuenta: perderías el acceso sin poder '
        .'recuperarlo tú mismo. Pídeselo a otro owner de la organización.';

    public static function make(): self
    {
        return new self(self::MESSAGE);
    }
}
