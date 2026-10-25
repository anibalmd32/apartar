<?php

declare(strict_types=1);

use App\Domain\Layaway\RescheduleWindow;
use Carbon\CarbonImmutable;

it('admite los dos bordes y nada fuera de ellos', function (): void {
    $window = new RescheduleWindow(CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-25'));

    expect($window->contains(CarbonImmutable::parse('2026-10-03')))->toBeFalse()
        ->and($window->contains(CarbonImmutable::parse('2026-10-04')))->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2026-10-25 23:59:00')))->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2026-10-26')))->toBeFalse()
        ->and($window->isEmpty())->toBeFalse()
        ->and($window->describe())->toBe('entre el 4 de octubre de 2026 y el 25 de octubre de 2026');
});

it('sin tope cuando es la última cuota', function (): void {
    $window = new RescheduleWindow(CarbonImmutable::parse('2026-10-04'), null);

    expect($window->contains(CarbonImmutable::parse('2030-01-01')))->toBeTrue()
        ->and($window->describe())->toBe('desde el 4 de octubre de 2026, sin tope');
});

it('queda vacía si la siguiente cuota vence antes de mañana', function (): void {
    $window = new RescheduleWindow(CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-03'));

    expect($window->isEmpty())->toBeTrue()
        ->and($window->contains(CarbonImmutable::parse('2026-10-03')))->toBeFalse()
        ->and($window->contains(CarbonImmutable::parse('2026-10-04')))->toBeFalse();
});
