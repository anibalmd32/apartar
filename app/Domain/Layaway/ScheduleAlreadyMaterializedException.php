<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use DomainException;
use Throwable;

/**
 * El apartado ya tenía cuotas al intentar materializar su cronograma
 * (`REQ-LAY-004` SP-5).
 *
 * Es el reintento de la activación con una inserción a medias: lo detecta
 * `UNIQUE(organization_id, layaway_id, number)` (`T-039`), la transacción
 * entera se deshace —ni cuotas nuevas, ni estado, ni `activated_at`— y esto
 * es lo que sube en lugar de la violación del índice, para que el log de un
 * job diga qué pasó y no qué índice saltó.
 *
 * **No es la segunda llamada sobre un apartado ya activo** (SP-6): esa la
 * rechaza la máquina de estados antes de llegar a insertar nada, con
 * `IllegalLayawayTransitionException`, porque `active → active` no está en la
 * matriz. Son dos garantías distintas y ninguna cubre a la otra.
 */
final class ScheduleAlreadyMaterializedException extends DomainException
{
    public function __construct(public readonly int|string $layawayId, ?Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'El apartado %s ya tiene cuotas y se intentó materializar su cronograma otra vez (REQ-LAY-004 SP-5). '
            .'No se insertó nada y el apartado sigue como estaba: las cuotas de un apartado se generan una sola vez, al activarlo.',
            $layawayId,
        ), previous: $previous);
    }
}
