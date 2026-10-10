<?php

declare(strict_types=1);

use App\Domain\Subscription\CatalogProductLimitReachedException;
use App\Models\Plan;

/**
 * El tope de productos por catálogo (`max_products_per_catalog`,
 * `REQ-SUB-004` RB-3, `REQ-CAT-023` SP-3): el mismo cuerpo que el resto de
 * límites, con su propia clave, y el plan que lo resuelve.
 */
it('dice el tope, lo que se pedía y el plan que lo admite', function (): void {
    $exception = CatalogProductLimitReachedException::of(
        used: 30,
        limit: 30,
        requested: 31,
        current: new Plan(['code' => 'free', 'name' => 'Free']),
        minimum: new Plan(['code' => 'pro', 'name' => 'Pro', 'max_products_per_catalog' => 200]),
    );

    expect($exception->getMessage())
        ->toBe('Tu plan Free permite 30 productos por catálogo, y este cambio lo dejaría con 31. Pro permite 200 por catálogo.');
    expect($exception->toArray())->toMatchArray([
        'metric' => 'products_per_catalog',
        'used' => 30,
        'limit' => 30,
        'minimumPlan' => 'pro',
        'minimumPlanName' => 'Pro',
    ]);
    expect($exception->toArray()['suggestion'])->toContain('quitar del catálogo');
    expect(CatalogProductLimitReachedException::STATUS)->toBe(422);
});

it('no inventa un camino cuando ningún plan lo admite', function (): void {
    $exception = CatalogProductLimitReachedException::of(1, 1, 2, new Plan(['code' => 'free', 'name' => 'Free']), null);

    expect($exception->getMessage())->toContain('1 producto por catálogo')->toEndWith('No hay ningún plan con un techo mayor.');
    expect($exception->toArray()['minimumPlan'])->toBeNull();
});

it('dice «sin límite» de un plan que no tiene tope', function (): void {
    $exception = CatalogProductLimitReachedException::of(5, 5, 6, new Plan(['name' => 'Free']), new Plan(['code' => 'pro', 'name' => 'Pro', 'max_products_per_catalog' => null]));

    expect($exception->getMessage())->toEndWith('Pro permite sin límite.');
});
