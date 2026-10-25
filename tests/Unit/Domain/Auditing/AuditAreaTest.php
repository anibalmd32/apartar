<?php

declare(strict_types=1);

use App\Domain\Auditing\AuditArea;
use App\Domain\Auditing\AuditEvent;

it('declara los seis bloques del requisito', function (): void {
    expect(array_map(fn (AuditArea $area): string => $area->value, AuditArea::cases()))
        ->toBe(['collections', 'catalog', 'customers', 'organization', 'subscription', 'platform']);
});

it('nombra cada bloque para una persona', function (AuditArea $area): void {
    expect($area->label())->not->toBe('')->and($area->label())->not->toBe($area->value);
})->with(AuditArea::cases());

it('reparte los eventos entre los seis bloques', function (): void {
    $counts = [];

    foreach (AuditArea::cases() as $area) {
        $counts[$area->value] = count($area->events());

        expect($area->events())->not->toBeEmpty();
    }

    expect(array_sum($counts))->toBe(count(AuditEvent::cases()));
});

it('agrupa en Organización los eventos que ya se emiten', function (): void {
    expect(AuditArea::Organization->events())->toHaveCount(11);
});
