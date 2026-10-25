<?php

declare(strict_types=1);

use App\Domain\Layaway\ScheduleAlreadyMaterializedException;

it('dice qué apartado y que no se tocó nada', function (): void {
    $cause = new RuntimeException('Duplicate entry');
    $exception = new ScheduleAlreadyMaterializedException(42, $cause);

    expect($exception->getMessage())->toBe(
        'El apartado 42 ya tiene cuotas y se intentó materializar su cronograma otra vez. '
        .'No se insertó nada y el apartado sigue como estaba: las cuotas de un apartado se generan una sola vez, al activarlo.'
    )->and($exception->layawayId)->toBe(42)
        ->and($exception->getPrevious())->toBe($cause);
});
