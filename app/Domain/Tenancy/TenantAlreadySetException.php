<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class TenantAlreadySetException extends RuntimeException
{
    public static function cannotSwitch(int $current, int $attempted): self
    {
        return new self(sprintf(
            'El tenant ya está fijado en la organización %d y se intentó cambiarlo a la %d. '
            .'Usa TenantContext::runFor() si de verdad necesitas ejecutar algo en otra organización.',
            $current,
            $attempted,
        ));
    }
}
