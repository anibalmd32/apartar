<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use RuntimeException;

final class ExchangeRateUnavailableException extends RuntimeException
{
    public static function unreachable(RateSource $source, string $reason): self
    {
        return new self(sprintf(
            'No se pudo obtener la tasa de %s: %s. Se conserva la última tasa persistida.',
            $source->value,
            $reason,
        ));
    }

    public static function unexpectedPayload(RateSource $source, string $expected): self
    {
        return new self(sprintf(
            'La respuesta de la fuente %s no tiene la forma esperada (%s). Revisa el parser: '
            .'reintentar no lo arregla.',
            $source->value,
            $expected,
        ));
    }
}
