<?php

declare(strict_types=1);

use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\StaleRate;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\Currency;
use Carbon\CarbonImmutable;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-07 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function publishedAgo(string $published, RateSource $source = RateSource::Bcv): RateSnapshot
{
    return new RateSnapshot('42.50000000', $source, CarbonImmutable::parse($published), CarbonImmutable::now());
}

it('a los 30 días exactos todavía no es vieja; un segundo después, sí', function (): void {
    expect(StaleRate::in(publishedAgo('2026-09-07 12:00:00'), Currency::VES))->toBeNull()
        ->and(StaleRate::in(publishedAgo('2026-09-07 11:59:59'), Currency::VES))->toBeInstanceOf(StaleRate::class);
});

it('no pide nada en dólares, con la tasa escrita a mano ni sin tasa', function (): void {
    expect(StaleRate::in(publishedAgo('2026-08-01 12:00:00'), Currency::USD))->toBeNull()
        ->and(StaleRate::in(new ManualRate('40.00'), Currency::VES))->toBeNull()
        ->and(StaleRate::in(null, Currency::VES))->toBeNull();
});

it('una tasa manual configurada hace meses también se confirma', function (): void {
    expect(StaleRate::in(publishedAgo('2026-07-01 12:00:00', RateSource::Manual), Currency::VES)?->toArray())
        ->toBe(['token' => 'manual@42.50000000@'.CarbonImmutable::parse('2026-07-01 12:00:00')->getTimestamp(), 'days' => 98, 'sourceLabel' => RateSource::Manual->label()]);
});

it('se confirma esa tasa —fuente, valor y publicación— y no otra', function (): void {
    $stale = StaleRate::in(publishedAgo('2026-08-20 09:00:00'), Currency::VES);
    expect($stale)->not->toBeNull();

    $stale?->assertConfirmedBy($stale->token());

    $sameInstant = fn (RateSnapshot $other): string => (string) StaleRate::in($other, Currency::VES)?->token();
    $otherValue = new RateSnapshot('43.00000000', RateSource::Bcv, CarbonImmutable::parse('2026-08-20 09:00:00'), CarbonImmutable::now());

    expect(fn () => $stale?->assertConfirmedBy(null))->toThrow(UnconfirmedStaleRateException::class, 'se publicó hace 48 días')
        ->and(fn () => $stale?->assertConfirmedBy($sameInstant(publishedAgo('2026-08-20 09:00:00', RateSource::Parallel))))->toThrow(UnconfirmedStaleRateException::class)
        ->and(fn () => $stale?->assertConfirmedBy($sameInstant($otherValue)))->toThrow(UnconfirmedStaleRateException::class);
});
