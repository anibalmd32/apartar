<?php

declare(strict_types=1);

use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\AllocationPlan;
use App\Domain\Payment\FifoAllocator;
use Carbon\CarbonImmutable;

/**
 * @param  list<array{0: int, 1: int}>  $installments
 */
function fifoSchedule(array $installments): AmortizationSchedule
{
    $scheduled = [];

    foreach ($installments as $position => [$amount, $paid]) {
        $number = $position + 1;
        $scheduled[] = new ScheduledInstallment(
            $number,
            CarbonImmutable::parse('2026-09-10', 'America/Caracas')->addDays(15 * $number),
            new Money($amount),
            new Money($paid),
            100 + $number,
        );
    }

    return new AmortizationSchedule($scheduled);
}

/**
 * @return array<int, int>
 */
function fifoAllocations(AllocationPlan $plan): array
{
    return array_column($plan->allocations, 'cents', 'installmentId');
}

it('cubre en cascada la cuota más antigua y salta las pagadas', function (): void {
    $plan = FifoAllocator::planFor(fifoSchedule([[3_000, 3_000], [3_000, 3_000], [3_000, 0], [3_000, 0], [3_000, 0]]), new Money(6_000));

    expect(fifoAllocations($plan))->toBe([103 => 3_000, 104 => 3_000])
        ->and($plan->unallocatedCents)->toBe(0)
        ->and($plan->isFullyAllocated())->toBeTrue();
});

it('termina primero la cuota a medias con lo que le falta', function (): void {
    $plan = FifoAllocator::planFor(fifoSchedule([[3_000, 1_000], [3_000, 0], [3_000, 0]]), new Money(5_000));

    expect(fifoAllocations($plan))->toBe([101 => 2_000, 102 => 3_000]);
});

it('deja a medias la primera si el pago no la cubre', function (): void {
    $plan = FifoAllocator::planFor(fifoSchedule([[3_000, 0], [3_000, 0]]), new Money(1));

    expect(fifoAllocations($plan))->toBe([101 => 1])->and($plan->unallocatedCents)->toBe(0);
});

it('cierra todas las cuotas con el saldo exacto y deja el excedente sin asignar', function (): void {
    $schedule = fifoSchedule([[3_000, 2_500], [3_000, 0], [3_001, 0]]);

    $exact = FifoAllocator::planFor($schedule, new Money(6_501));
    $over = FifoAllocator::planFor($schedule, new Money(10_000));

    expect(fifoAllocations($exact))->toBe([101 => 500, 102 => 3_000, 103 => 3_001])
        ->and($exact->unallocatedCents)->toBe(0)
        ->and(fifoAllocations($over))->toBe([101 => 500, 102 => 3_000, 103 => 3_001])
        ->and($over->unallocatedCents)->toBe(3_499);
});

it('no asigna nada si no queda nada por cubrir', function (array $installments): void {
    $plan = FifoAllocator::planFor(fifoSchedule($installments), new Money(3_000));

    expect($plan->allocations)->toBe([])->and($plan->unallocatedCents)->toBe(3_000);
})->with([
    'saldado' => [[[3_000, 3_000], [3_000, 3_000]]],
    'vacío' => [[]],
]);

it('no da nada a una cuota con más pagado que su importe', function (): void {
    $plan = FifoAllocator::planFor(fifoSchedule([[3_000, 3_500], [3_000, 0]]), new Money(1_000));

    expect(fifoAllocations($plan))->toBe([102 => 1_000]);
});

it('solo reparte un positivo en dólares', function (Money $amount): void {
    FifoAllocator::planFor(fifoSchedule([[3_000, 0]]), $amount);
})->throws(InvalidArgumentException::class)->with([
    'cero' => [new Money(0)],
    'negativo' => [new Money(-100)],
    'en bolívares' => [new Money(3_000, Currency::VES)],
]);

it('no reparte sobre un cronograma sin materializar', function (): void {
    FifoAllocator::planFor(new AmortizationSchedule([new ScheduledInstallment(1, CarbonImmutable::parse('2026-09-25'), new Money(3_000))]), new Money(3_000));
})->throws(LogicException::class);

it('cumple las invariantes del reparto con 500 casos al azar', function (): void {
    mt_srand(20_261_004);

    $cases = [];

    for ($i = 0; $i < 120; $i++) {
        $amount = mt_rand(1_000, 50_000);
        $count = mt_rand(1, 12);
        $cases[] = [array_fill(0, $count, [$amount, 0]), mt_rand(1, $amount * ($count + 1))];
    }

    for ($i = 0; $i < 120; $i++) {
        $installments = [];

        foreach (range(1, mt_rand(1, 24)) as $ignored) {
            $amount = mt_rand(1, 40_000);
            $installments[] = [$amount, [0, $amount, mt_rand(0, $amount)][mt_rand(0, 2)]];
        }

        $cases[] = [$installments, mt_rand(1, 500_000)];
    }

    for ($i = 0; $i < 120; $i++) {
        $installments = [];

        foreach (range(1, mt_rand(2, 18)) as $ignored) {
            $amount = mt_rand(100, 30_000);
            $installments[] = [$amount, mt_rand(0, 1) === 0 ? 0 : mt_rand(0, $amount)];
        }

        $pending = array_map(fn (array $row): int => max(0, $row[0] - $row[1]), $installments);
        $boundary = array_sum(array_slice($pending, 0, mt_rand(1, count($pending))));
        $cases[] = [$installments, max(1, $boundary + [-1, 0, 1][mt_rand(0, 2)])];
    }

    for ($i = 0; $i < 70; $i++) {
        $amount = mt_rand(500, 20_000);
        $count = mt_rand(1, 10);
        $cases[] = [array_fill(0, $count, [$amount, mt_rand(0, $amount)]), $amount * $count + mt_rand(1, 100_000)];
    }

    for ($i = 0; $i < 50; $i++) {
        $installments = array_map(fn (): array => [mt_rand(1, 9_999), 0], range(1, 36));
        $cases[] = [$installments, mt_rand(1, 400_000)];
    }

    for ($i = 0; $i < 20; $i++) {
        $cases[] = [array_fill(0, mt_rand(1, 5), [mt_rand(1, 3), 0]), mt_rand(1, 3)];
    }

    expect($cases)->toHaveCount(500);

    foreach ($cases as $index => [$installments, $payment]) {
        $plan = FifoAllocator::planFor(fifoSchedule($installments), new Money($payment));
        $given = fifoAllocations($plan);
        $context = sprintf('caso %d: cuotas %s, pago %d', $index, json_encode($installments), $payment);

        expect($plan->allocatedCents())->toBe($payment - $plan->unallocatedCents, $context);

        expect(array_diff(array_keys($given), range(101, 100 + count($installments))))->toBe([], $context);

        $lastAllocated = $plan->allocations === [] ? 0 : (int) array_key_last($given) - 100;

        foreach ($installments as $position => [$amount, $paid]) {
            $number = $position + 1;
            $pending = max(0, $amount - $paid);
            $received = $given[100 + $number] ?? 0;

            expect($received)->toBeLessThanOrEqual($pending, $context);

            if ($number < $lastAllocated) {
                expect($received)->toBe($pending, $context);
            }
        }

        if ($plan->unallocatedCents > 0) {
            $totalPending = array_sum(array_map(fn (array $row): int => max(0, $row[0] - $row[1]), $installments));
            expect($plan->allocatedCents())->toBe($totalPending, $context);
        }
    }
});
