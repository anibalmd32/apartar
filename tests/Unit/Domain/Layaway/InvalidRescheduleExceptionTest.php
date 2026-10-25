<?php

declare(strict_types=1);

use App\Domain\Layaway\InvalidRescheduleException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\RescheduleWindow;
use Carbon\CarbonImmutable;

it('dice el rango válido cuando la fecha cae fuera', function (): void {
    $window = new RescheduleWindow(CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-25'));
    $exception = InvalidRescheduleException::outsideWindow(2, $window);

    expect($exception->getMessage())->toBe('La nueva fecha de la cuota 2 tiene que estar entre el 4 de octubre de 2026 y el 25 de octubre de 2026.')
        ->and($exception->window)->toBe($window);
});

it('dice que no hay ningún día posible si la ventana está vacía', function (): void {
    $window = new RescheduleWindow(CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-03'));

    expect(InvalidRescheduleException::outsideWindow(2, $window)->getMessage())
        ->toBe('La cuota 2 no se puede mover: la siguiente vence antes de mañana.');
});

it('rechaza una cuota pagada y un apartado que no se cobra', function (): void {
    expect(InvalidRescheduleException::alreadyPaid(1)->getMessage())->toBe('La cuota 1 ya está pagada y no se puede reprogramar.')
        ->and(InvalidRescheduleException::alreadyPaid(1)->window)->toBeNull()
        ->and(InvalidRescheduleException::layawayNotCollecting(LayawayStatus::Cancelled)->getMessage())
        ->toBe('Un apartado cancelado no tiene cuotas que reprogramar.');
});
