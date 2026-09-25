<?php

declare(strict_types=1);

use App\Domain\Layaway\InvalidScheduleException;

/**
 * `REQ-LAY-004` RB-7: el mensaje dice el rango y lo que se pidió.
 */
it('dice el rango de cuotas', function (): void {
    expect(InvalidScheduleException::installmentsOutOfRange(40)->getMessage())
        ->toBe('Un apartado se paga en entre 1 y 36 cuotas, y se pidieron 40.');
});

it('dice el rango de la frecuencia', function (): void {
    expect(InvalidScheduleException::frequencyOutOfRange(120)->getMessage())
        ->toBe('Las cuotas van cada 1 a 90 días, y se pidieron 120.');
});

it('es un error de argumento', function (): void {
    expect(InvalidScheduleException::frequencyOutOfRange(0))->toBeInstanceOf(InvalidArgumentException::class);
});
