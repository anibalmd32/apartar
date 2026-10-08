<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use RuntimeException;

/**
 * Bolívares que se convertirían con una tasa de más de 30 días sin que nadie
 * lo confirmara (`REQ-FX-006` SP-5).
 *
 * El pago congela esa tasa para siempre (RB-3): si los bolívares de hoy valen
 * la mitad que hace dos meses, el cliente quedaría acreditado con el doble, y
 * no hay operación que lo corrija. Así que la pantalla lo pide **y el servidor
 * también**, antes de aplicar nada. La salida que no exige confirmar es
 * escribir la tasa del día del pago (SP-3).
 */
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
