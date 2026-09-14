<?php

declare(strict_types=1);

use App\Domain\Subscription\UsageMetric;
use App\Domain\Subscription\UsageState;

/**
 * Lo consumido frente al techo (`REQ-SUB-003` RB-6, `REQ-SUB-001` SP-2).
 *
 * El caso que justifica este archivo es el del techo `null`: **ilimitado no es
 * cero**, y el sitio donde esa distinción se pierde es siempre el último, al
 * derivar el porcentaje o lo que queda.
 */
it('cuenta lo que queda contra el techo del plan', function (): void {
    $state = UsageState::of(UsageMetric::Users, used: 2, limit: 5);

    expect($state->remaining())->toBe(3);
    expect($state->isAtLimit)->toBeFalse();
    expect($state->admits(3))->toBeTrue();
    expect($state->admits(4))->toBeFalse();
});

it('trata el techo nulo como ilimitado y no como cero', function (): void {
    $state = UsageState::of(UsageMetric::Users, used: 900, limit: null);

    expect($state->remaining())->toBeNull();
    expect($state->percentage)->toBeNull();
    expect($state->isAtLimit)->toBeFalse();
    expect($state->admits(1_000))->toBeTrue();
});

/**
 * `REQ-SUB-008` RB-4 · una bajada de plan deja el consumo por encima del
 * techo, y ahí lo que queda es nada, no una cifra negativa que después haya
 * que interpretar en cada pantalla.
 */
it('no devuelve restante negativo con el consumo por encima del techo', function (): void {
    $state = UsageState::of(UsageMetric::Users, used: 8, limit: 5);

    expect($state->remaining())->toBe(0);
    expect($state->isAtLimit)->toBeTrue();
    expect($state->admits(1))->toBeFalse();
    expect($state->percentage)->toBe(100);
});

it('avisa a partir del ochenta por ciento', function (int $used, bool $warns): void {
    expect(UsageState::of(UsageMetric::Products, $used, limit: 10)->deservesWarning())->toBe($warns);
})->with([
    'siete de diez' => [7, false],
    'ocho de diez' => [8, true],
    'diez de diez' => [10, true],
]);

/**
 * Un techo de cero no da porcentaje: dividir por él no significa «todo
 * consumido», significa que el plan no admite ninguno.
 */
it('no calcula porcentaje sobre un techo de cero', function (): void {
    $state = UsageState::of(UsageMetric::Catalogs, used: 0, limit: 0);

    expect($state->percentage)->toBeNull();
    expect($state->isAtLimit)->toBeTrue();
});

it('viaja a las page props con las cuatro claves del contrato', function (): void {
    expect(UsageState::of(UsageMetric::Users, 1, 5)->toArray())
        ->toBe(['used' => 1, 'limit' => 5, 'isAtLimit' => false, 'percentage' => 20]);
});
