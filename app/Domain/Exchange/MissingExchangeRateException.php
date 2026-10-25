<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use RuntimeException;

final class MissingExchangeRateException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'No hay ninguna tasa de cambio disponible para conciliar este pago. '
        .'Fija la tasa manual en Ajustes → Tasa de cambio y vuelve a intentarlo.';

    public static function whileSettling(RateSource $source): self
    {
        return new self(sprintf(
            '%s (fuente configurada: %s, sin ninguna tasa en el histórico).',
            self::MESSAGE,
            $source->value,
        ));
    }
}
