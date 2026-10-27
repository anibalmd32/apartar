<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use RuntimeException;

final class UnconfirmedRateDeviationException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct(public readonly RateDeviation $deviation)
    {
        parent::__construct(sprintf(
            'La tasa que escribiste (%s) está %d %% %s de la tasa %s del día (%s). Confírmala si es correcta.',
            RateDeviation::display($deviation->manual->rate),
            $deviation->percent,
            $deviation->isAbove() ? 'por encima' : 'por debajo',
            $deviation->reference->source->label(),
            RateDeviation::display($deviation->reference->rate),
        ));
    }
}
