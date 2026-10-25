<?php

declare(strict_types=1);

use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

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

it('responde vacío sin reventar', function (): void {
    $schedule = new AmortizationSchedule([]);

    expect($schedule->isEmpty())->toBeTrue()
        ->and($schedule->count())->toBe(0)
        ->and($schedule->totalAmount()->cents)->toBe(0)
        ->and($schedule->totalAmount()->currency)->toBe(Currency::USD)
        ->and($schedule->firstUnpaid())->toBeNull()
        ->and($schedule->last())->toBeNull();
});

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

it('rechaza una numeración que salta, se repite o no empieza en 1', function (array $numbers): void {
    expect(fn () => new AmortizationSchedule(array_map(fn (int $n): ScheduledInstallment => installmentAt($n, 1_000), $numbers)))
        ->toThrow(InvalidArgumentException::class, 'numeradas 1…n');
})->with([[[1, 3]], [[1, 1]], [[2, 3]], [[2, 1]]]);

it('rechaza una cuota en otra moneda', function (): void {
    expect(fn () => new AmortizationSchedule([installmentAt(1, 1_000, currency: Currency::VES)]))
        ->toThrow(InvalidArgumentException::class, 'La cuota 1 está en VES');
});

it('acota la ventana de una reprogramación entre las cuotas vecinas', function (): void {
    $schedule = new AmortizationSchedule([installmentAt(1, 3_000), installmentAt(2, 3_000), installmentAt(3, 3_000)]);
    $on = fn (string $day): CarbonImmutable => CarbonImmutable::parse($day, 'America/Caracas');

    $middle = $schedule->validMoveWindow(2, $on('2026-10-01'));
    $early = $schedule->validMoveWindow(2, $on('2026-09-20'));
    $last = $schedule->validMoveWindow(3, $on('2026-10-01'));
    $overdueFirst = $schedule->validMoveWindow(1, $on('2026-09-30'));

    expect($middle->earliest->toDateString())->toBe('2026-10-02')
        ->and($middle->latest?->toDateString())->toBe('2026-10-25')
        ->and($early->earliest->toDateString())->toBe('2026-09-25')
        ->and($last->latest)->toBeNull()
        ->and($overdueFirst->earliest->toDateString())->toBe('2026-10-01')
        ->and($overdueFirst->latest?->toDateString())->toBe('2026-10-10');
});

it('no da ventana de una cuota que no está en el cronograma', function (int $number): void {
    (new AmortizationSchedule([installmentAt(1, 3_000)]))->validMoveWindow($number, CarbonImmutable::parse('2026-10-01'));
})->throws(InvalidArgumentException::class)->with([0, 2]);
