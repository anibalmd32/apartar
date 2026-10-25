<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;

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

it('guarda la tasa como cadena decimal y no como float', function (): void {
    $snapshot = rateSnapshotOf(CarbonImmutable::now());

    expect($snapshot->rate)->toBeString();

    $rate = new ReflectionProperty(RateSnapshot::class, 'rate');

    expect((string) $rate->getType())->toBe('string');
});

it('es inmutable', function (): void {
    expect((new ReflectionClass(RateSnapshot::class))->isReadOnly())->toBeTrue();
});

it('mide la obsolescencia sobre publishedAt', function (): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = rateSnapshotOf(
        publishedAt: CarbonImmutable::now()->subHours(30),
        fetchedAt: CarbonImmutable::now()->subMinutes(5),
    );

    expect($snapshot->isStale())->toBeTrue();
});

it('no da por obsoleta una tasa recién publicada que leímos hace horas', function (): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = rateSnapshotOf(
        publishedAt: CarbonImmutable::now()->subHour(),
        fetchedAt: CarbonImmutable::now()->subHours(48),
    );

    expect($snapshot->isStale())->toBeFalse();
});

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

it('admite un umbral distinto del de la vitrina', function (): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = rateSnapshotOf(CarbonImmutable::now()->subHours(48));

    expect($snapshot->isStale(72))->toBeFalse()
        ->and($snapshot->isStale(24))->toBeTrue();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});
