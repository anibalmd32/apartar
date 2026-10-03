<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayNotDeliverableException;
use App\Domain\Layaway\LayawayStatus;

/**
 * `REQ-LAY-010` RB-1: solo un apartado pagado se entrega.
 */
it('dice en qué estado está el apartado que no se puede entregar', function (): void {
    $exception = new LayawayNotDeliverableException(LayawayStatus::Overdue);

    expect($exception->getMessage())->toBe('Solo se entrega un apartado pagado, y este está en mora.')
        ->and($exception->status)->toBe(LayawayStatus::Overdue);
});
