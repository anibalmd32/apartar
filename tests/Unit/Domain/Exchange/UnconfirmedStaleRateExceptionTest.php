<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\StaleRate;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\Currency;
use Carbon\CarbonImmutable;

it('dice la fuente, la antigüedad y la salida', function (): void {
    CarbonImmutable::setTestNow('2026-10-07 12:00:00');
    $stale = StaleRate::in(new RateSnapshot('40.00000000', RateSource::Parallel, CarbonImmutable::parse('2026-08-23 12:00:00'), CarbonImmutable::now()), Currency::VES);
    expect($stale)->not->toBeNull();

    $exception = new UnconfirmedStaleRateException($stale ?? throw new LogicException);

    expect(UnconfirmedStaleRateException::STATUS)->toBe(422)
        ->and($exception->stale)->toBe($stale)
        ->and($exception->getMessage())->toBe(sprintf(
            'La tasa %s que se usaría se publicó hace 45 días. Confírmala para convertir con ella, o escribe la tasa del día del pago.',
            RateSource::Parallel->label(),
        ));

    CarbonImmutable::setTestNow();
});
