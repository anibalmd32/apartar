<?php

declare(strict_types=1);

use App\Domain\Auditing\AuditArea;
use App\Domain\Auditing\AuditEvent;

/**
 * Los seis bloques de `REQ-BOF-005` RB-1.
 *
 * No es una taxonomía de la pantalla: es la estructura del requisito, y por eso
 * lo que se fija aquí es que sean **esos seis** y que ninguno se quede vacío.
 */
it('declara los seis bloques del requisito', function (): void {
    expect(array_map(fn (AuditArea $area): string => $area->value, AuditArea::cases()))
        ->toBe(['collections', 'catalog', 'customers', 'organization', 'subscription', 'platform']);
});

it('nombra cada bloque para una persona', function (AuditArea $area): void {
    expect($area->label())->not->toBe('')->and($area->label())->not->toBe($area->value);
})->with(AuditArea::cases());

/**
 * Cada bloque tiene eventos y ninguno se los queda todos: si `area()` cayera
 * por descuido siempre del mismo lado, el filtro de la pantalla enseñaría cinco
 * grupos vacíos y nadie miraría por qué.
 */
it('reparte los eventos entre los seis bloques', function (): void {
    $counts = [];

    foreach (AuditArea::cases() as $area) {
        $counts[$area->value] = count($area->events());

        expect($area->events())->not->toBeEmpty();
    }

    expect(array_sum($counts))->toBe(count(AuditEvent::cases()));
});

/**
 * El bloque Organización es el único con emisores hoy (`T-013`…`T-015`), así
 * que es el que la pantalla puede enseñar lleno desde el primer día.
 */
it('agrupa en Organización los eventos que ya se emiten', function (): void {
    expect(AuditArea::Organization->events())->toHaveCount(10);
});
