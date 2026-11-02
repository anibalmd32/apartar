<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use RuntimeException;

final class LayawayNotDeliverableException extends RuntimeException
{
    public function __construct(public readonly LayawayStatus $status)
    {
        parent::__construct(sprintf(
            'Un apartado %s no se puede entregar.',
            mb_strtolower($status->label()),
        ));
    }
}
