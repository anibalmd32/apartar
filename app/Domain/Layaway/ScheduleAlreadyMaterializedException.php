<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use DomainException;
use Throwable;

final class ScheduleAlreadyMaterializedException extends DomainException
{
    public function __construct(public readonly int|string $layawayId, ?Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'El apartado %s ya tiene cuotas y se intentó materializar su cronograma otra vez. '
            .'No se insertó nada y el apartado sigue como estaba: las cuotas de un apartado se generan una sola vez, al activarlo.',
            $layawayId,
        ), previous: $previous);
    }
}
