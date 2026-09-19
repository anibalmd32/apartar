<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;

/**
 * El *value object* que atraviesa todo el dinero del sistema.
 *
 * Lo que se fija aquí es **cuál de las dos fechas mide la obsolescencia**, que
 * es la única decisión del tipo y la que se puede equivocar en silencio: una
 * tasa de hace una semana releída hace un minuto parecería fresca, y el aviso
 * de `REQ-FX-005` RB-3 no se encendería nunca.
 */
function rateSnapshotOf(CarbonImmutable $publishedAt, ?CarbonImmutable $fetchedAt = null): RateSnapshot
{
    return new RateSnapshot(
        rate: '42.50000000',
        source: RateSource::Bcv,
        publishedAt: $publishedAt,
        fetchedAt: $fetchedAt ?? CarbonImmutable::now(),
    );
}

it('lleva las cuatro propiedades del contrato del módulo 13', function (): void {
    $publishedAt = CarbonImmutable::parse('2026-09-18 10:00:00');
    $fetchedAt = CarbonImmutable::parse('2026-09-18 10:30:00');

    $snapshot = new RateSnapshot('42.50000000', RateSource::Parallel, $publishedAt, $fetchedAt);

    expect($snapshot->rate)->toBe('42.50000000')
        ->and($snapshot->source)->toBe(RateSource::Parallel)
        ->and($snapshot->publishedAt)->toEqual($publishedAt)
        ->and($snapshot->fetchedAt)->toEqual($fetchedAt);
});

/**
 * `REQ-FX-001` RB-1: la coma flotante está prohibida **en el código**, no solo
 * en el esquema. Una tasa en `float` reintroduce por la puerta de atrás el
 * error de redondeo que toda la aritmética monetaria evita.
 */
it('guarda la tasa como cadena decimal y no como float', function (): void {
    $snapshot = rateSnapshotOf(CarbonImmutable::now());

    expect($snapshot->rate)->toBeString();

    $rate = new ReflectionProperty(RateSnapshot::class, 'rate');

    expect((string) $rate->getType())->toBe('string');
});

it('es inmutable', function (): void {
    expect((new ReflectionClass(RateSnapshot::class))->isReadOnly())->toBeTrue();
});

/**
 * La aserción central de la tarea: **`publishedAt`, no `fetchedAt`**.
 */
it('mide la obsolescencia sobre publishedAt', function (): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = rateSnapshotOf(
        publishedAt: CarbonImmutable::now()->subHours(30),
        fetchedAt: CarbonImmutable::now()->subMinutes(5),
    );

    expect($snapshot->isStale())->toBeTrue();
});

/**
 * Y la cara contraria, que es la que de verdad distingue las dos columnas: la
 * ingesta lleva horas parada, pero lo que el BCV publicó sigue siendo de hace
 * un rato. Eso **no** es una tasa obsoleta.
 */
it('no da por obsoleta una tasa recién publicada que leímos hace horas', function (): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = rateSnapshotOf(
        publishedAt: CarbonImmutable::now()->subHour(),
        fetchedAt: CarbonImmutable::now()->subHours(48),
    );

    expect($snapshot->isStale())->toBeFalse();
});

/**
 * `REQ-FX-005` RB-3 dice «más de 24 h»: a las 24 exactas todavía no lo es.
 */
it('usa 24 h como umbral por defecto, sin incluirlas', function (int $hours, bool $stale): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    expect(rateSnapshotOf(CarbonImmutable::now()->subHours($hours))->isStale())->toBe($stale);
})->with([
    'recién publicada' => [0, false],
    'doce horas' => [12, false],
    'veinticuatro exactas' => [24, false],
    'veinticinco' => [25, true],
    'una semana' => [168, true],
]);

/**
 * El umbral es un parámetro porque no todos los usos miden lo mismo: el sello
 * del catálogo avisa a las 24 h y la confirmación de `REQ-FX-006` SP-5 mira
 * treinta días atrás.
 */
it('admite un umbral distinto del de la vitrina', function (): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = rateSnapshotOf(CarbonImmutable::now()->subHours(48));

    expect($snapshot->isStale(72))->toBeFalse()
        ->and($snapshot->isStale(24))->toBeTrue();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});
