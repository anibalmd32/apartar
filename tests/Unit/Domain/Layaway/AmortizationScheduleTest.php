<?php

declare(strict_types=1);

use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * El cronograma como colección (`REQ-LAY-004` RB-5, RB-8; SP-2).
 */
function installmentAt(int $number, int $amount, int $paid = 0, Currency $currency = Currency::USD): ScheduledInstallment
{
    return new ScheduledInstallment(
        $number,
        CarbonImmutable::parse('2026-09-10', 'America/Caracas')->addDays(15 * $number),
        new Money($amount, $currency),
        new Money($paid, $currency),
    );
}

it('suma sus cuotas', function (): void {
    $schedule = new AmortizationSchedule([installmentAt(1, 3_333), installmentAt(2, 3_333), installmentAt(3, 3_334)]);

    expect($schedule->totalAmount()->cents)->toBe(10_000)
        ->and($schedule->count())->toBe(3)
        ->and($schedule->isEmpty())->toBeFalse()
        ->and($schedule->last()?->number)->toBe(3);
});

/**
 * SP-2: el cronograma vacío responde a todo sin reventar, y su total es un
 * cero en dólares.
 */
it('responde vacío sin reventar', function (): void {
    $schedule = new AmortizationSchedule([]);

    expect($schedule->isEmpty())->toBeTrue()
        ->and($schedule->count())->toBe(0)
        ->and($schedule->totalAmount()->cents)->toBe(0)
        ->and($schedule->totalAmount()->currency)->toBe(Currency::USD)
        ->and($schedule->firstUnpaid())->toBeNull()
        ->and($schedule->last())->toBeNull();
});

/**
 * RB-8: la primera que aún se debe, saltando las saldadas y **no** las que
 * están a medias.
 */
it('encuentra la primera cuota que aún se debe', function (): void {
    $schedule = new AmortizationSchedule([
        installmentAt(1, 3_000, paid: 3_000),
        installmentAt(2, 3_000, paid: 1_000),
        installmentAt(3, 3_000),
    ]);

    expect($schedule->firstUnpaid()?->number)->toBe(2);
});

it('no devuelve ninguna cuando todas están saldadas', function (): void {
    $schedule = new AmortizationSchedule([installmentAt(1, 3_000, paid: 3_000), installmentAt(2, 3_000, paid: 3_000)]);

    expect($schedule->firstUnpaid())->toBeNull();
});

/**
 * La numeración 1…n en orden es la que protege
 * `UNIQUE(organization_id, layaway_id, number)` al materializar.
 */
it('rechaza una numeración que salta, se repite o no empieza en 1', function (array $numbers): void {
    expect(fn () => new AmortizationSchedule(array_map(fn (int $n): ScheduledInstallment => installmentAt($n, 1_000), $numbers)))
        ->toThrow(InvalidArgumentException::class, 'numeradas 1…n');
})->with([[[1, 3]], [[1, 1]], [[2, 3]], [[2, 1]]]);

it('rechaza una cuota en otra moneda', function (): void {
    expect(fn () => new AmortizationSchedule([installmentAt(1, 1_000, currency: Currency::VES)]))
        ->toThrow(InvalidArgumentException::class, 'La cuota 1 está en VES');
});
