<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use RuntimeException;

final class UnconfirmedStaleRateException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct(public readonly StaleRate $stale)
    {
        parent::__construct(sprintf(
            'La tasa %s que se usaría se publicó hace %d días. Confírmala para convertir con ella, o escribe la tasa del día del pago.',
            $stale->snapshot->source->label(),
            $stale->ageInDays(),
        ));
    }
}
