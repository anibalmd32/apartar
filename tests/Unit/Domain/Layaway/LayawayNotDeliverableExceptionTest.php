<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayNotDeliverableException;
use App\Domain\Layaway\LayawayStatus;

it('dice en qué estado está el apartado que no se puede entregar', function (): void {
    $exception = new LayawayNotDeliverableException(LayawayStatus::Cancelled);

    expect($exception->getMessage())->toBe('Un apartado cancelado no se puede entregar.')
        ->and($exception->status)->toBe(LayawayStatus::Cancelled);
});
