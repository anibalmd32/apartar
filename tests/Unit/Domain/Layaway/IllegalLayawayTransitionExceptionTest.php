<?php

declare(strict_types=1);

use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayStatus;

/**
 * `REQ-LAY-005` RB-2: el mensaje dice desde dónde, hacia dónde, y adónde sí.
 */
it('dice desde dónde, hacia dónde y adónde sí se podía ir', function (): void {
    $exception = new IllegalLayawayTransitionException(LayawayStatus::PendingDownPayment, LayawayStatus::Completed, 42);

    expect($exception->getMessage())->toBe(
        'El apartado 42 no puede pasar de «pending_down_payment» a «completed». '
        .'Desde «pending_down_payment» solo se puede ir a «active», «expired», «cancelled» (REQ-LAY-005 RB-1).'
    );
});

/**
 * SP-1 y SP-2: desde un terminal, el mensaje dice que es terminal.
 */
it('explica que un terminal no se reabre', function (): void {
    $exception = new IllegalLayawayTransitionException(LayawayStatus::Completed, LayawayStatus::Cancelled);

    expect($exception->getMessage())->toBe(
        'El apartado no puede pasar de «completed» a «cancelled». '
        .'«completed» es un estado terminal: no se reabre ni pasa a ningún otro (REQ-LAY-005 RB-8).'
    )->and($exception->from)->toBe(LayawayStatus::Completed)
        ->and($exception->to)->toBe(LayawayStatus::Cancelled);
});
