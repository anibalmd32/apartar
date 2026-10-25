<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use RuntimeException;

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
