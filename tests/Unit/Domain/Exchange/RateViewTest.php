<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\RateView;
use Carbon\CarbonImmutable;

it('proyecta la tasa que hay', function (): void {
    $snapshot = new RateSnapshot(
        '42.50000000',
        RateSource::Bcv,
        CarbonImmutable::parse('2026-09-18 09:00:00'),
        CarbonImmutable::parse('2026-09-18 09:30:00'),
    );

    expect(RateView::of($snapshot, RateSource::Bcv)->toArray())
        ->toMatchArray(['rate' => 42.5, 'source' => 'bcv']);
});

it('viaja con la fecha de publicación y no con la de la ingesta', function (): void {
    $snapshot = new RateSnapshot(
        '42.50000000',
        RateSource::Bcv,
        CarbonImmutable::parse('2026-09-18 09:00:00'),
        CarbonImmutable::parse('2026-09-18 23:30:00'),
    );

    $payload = RateView::of($snapshot, RateSource::Bcv)->toArray();

    expect($payload)->not->toHaveKey('fetchedAt');
    expect(CarbonImmutable::parse((string) $payload['publishedAt'])->format('H:i'))->toBe('09:00');
});

it('expresa la ausencia de tasa como null y nunca como cero', function (): void {
    $payload = RateView::of(null, RateSource::Parallel)->toArray();

    expect($payload['rate'])->toBeNull();
    expect($payload['rate'])->not->toBe(0.0);
    expect($payload['publishedAt'])->toBeNull();
    expect($payload)->toMatchArray(['source' => 'parallel', 'isStale' => false]);
});

it('no marca como obsoleta una ausencia', function (): void {
    expect(RateView::of(null, RateSource::Bcv)->isStale)->toBeFalse();
});

it('marca la obsolescencia sobre publishedAt', function (int $hours, bool $stale): void {
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = new RateSnapshot(
        '42.50000000',
        RateSource::Bcv,
        CarbonImmutable::now()->subHours($hours),
        CarbonImmutable::now(),
    );

    expect(RateView::of($snapshot, RateSource::Bcv)->isStale)->toBe($stale);

    CarbonImmutable::setTestNow();
})->with([
    'recién publicada' => [1, false],
    'de hace un día justo' => [24, false],
    'de hace treinta horas' => [30, true],
]);

it('entrega la tasa como número para la pantalla', function (): void {
    $snapshot = new RateSnapshot(
        '36.12345678',
        RateSource::Parallel,
        CarbonImmutable::now(),
        CarbonImmutable::now(),
    );

    $view = RateView::of($snapshot, RateSource::Parallel);

    expect($view->rate)->toBeFloat();
    expect($view->rate)->toBe(36.12345678);
});

it('conserva la fuente pedida cuando no hay tasa', function (): void {
    expect(RateView::of(null, RateSource::Manual)->source)->toBe(RateSource::Manual);
});
