<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use RuntimeException;

/**
 * Se intentó marcar como entregado un apartado que no está pagado
 * (`REQ-LAY-010` RB-1, SP-1, SP-3).
 *
 * Solo un `completed` se entrega. Un cancelado cuyo producto salió por error
 * de la tienda tampoco: queda como incidencia fuera del sistema (SP-3), y
 * dejarlo marcar sería inventar una entrega que la deuda no respalda.
 */
final class LayawayNotDeliverableException extends RuntimeException
{
    public function __construct(public readonly LayawayStatus $status)
    {
        parent::__construct(sprintf(
            'Solo se entrega un apartado pagado, y este está %s.',
            mb_strtolower($status->label()),
        ));
    }
}
