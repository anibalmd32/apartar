<?php

declare(strict_types=1);

use App\Domain\Subscription\UsageDrift;
use App\Domain\Subscription\UsageMetric;

/**
 * Un contador descuadrado (`REQ-SUB-003` SP-1) y la única diferencia que no es
 * un fallo: los catálogos que caducaron desde la última pasada.
 */
it('trata cualquier diferencia como deriva salvo los catálogos que caducaron', function (): void {
    foreach (UsageMetric::cases() as $metric) {
        expect((new UsageDrift(1, $metric, stored: 2, measured: 3))->isUnexpected())->toBeTrue();
        expect((new UsageDrift(1, $metric, stored: 3, measured: 2))->isUnexpected())
            ->toBe(! $metric->shrinksWithTime());
    }
});

it('solo los catálogos bajan solos con el reloj', function (): void {
    expect(array_values(array_filter(UsageMetric::cases(), fn (UsageMetric $metric): bool => $metric->shrinksWithTime())))
        ->toBe([UsageMetric::Catalogs]);
});

it('se registra con la organización, la métrica y las dos cifras', function (): void {
    expect((new UsageDrift(7, UsageMetric::Products, stored: 9, measured: 2))->toArray())->toBe([
        'organization_id' => 7,
        'metric' => 'products',
        'stored' => 9,
        'measured' => 2,
    ]);
});
