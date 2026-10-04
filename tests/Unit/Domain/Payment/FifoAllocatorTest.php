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
 * El reparto FIFO de un pago entre cuotas (`REQ-PAY-005` RB-4, RB-6;
 * `REQ-LAY-008` RB-3), sin base de datos.
 *
 * Los ids de cuota son `100 + número`, para que un reparto que confundiera el
 * número con el id se notara.
 */

/**
 * Un cronograma materializado: cada cuota con su importe y lo pagado.
 *
 * @param  list<array{0: int, 1: int}>  $installments  [importe, pagado] en centavos, en orden
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
 * @return array<int, int> id de cuota => centavos
 */
function fifoAllocations(AllocationPlan $plan): array
{
    return array_column($plan->allocations, 'cents', 'installmentId');
}

/**
 * El *happy path* de `REQ-PAY-005`: cuotas 1 y 2 pagadas, 3, 4 y 5 de $30
 * pendientes, y un pago de $60: van $30 a la 3 y $30 a la 4; la 5 queda.
 */
it('cubre en cascada la cuota más antigua y salta las pagadas', function (): void {
    $plan = FifoAllocator::planFor(fifoSchedule([[3_000, 3_000], [3_000, 3_000], [3_000, 0], [3_000, 0], [3_000, 0]]), new Money(6_000));

    expect(fifoAllocations($plan))->toBe([103 => 3_000, 104 => 3_000])
        ->and($plan->unallocatedCents)->toBe(0)
        ->and($plan->isFullyAllocated())->toBeTrue();
});

/**
 * La cuota a medias se termina primero, con lo que le falta, y no con su
 * importe entero: pagado $10 de $30, un pago de $50 da $20 a la 1 y $30 a la 2.
 */
it('termina primero la cuota a medias con lo que le falta', function (): void {
    $plan = FifoAllocator::planFor(fifoSchedule([[3_000, 1_000], [3_000, 0], [3_000, 0]]), new Money(5_000));

    expect(fifoAllocations($plan))->toBe([101 => 2_000, 102 => 3_000]);
});

/**
 * Un pago que no llega a cubrir la primera se queda entero en ella, a medias.
 */
it('deja a medias la primera si el pago no la cubre', function (): void {
    $plan = FifoAllocator::planFor(fifoSchedule([[3_000, 0], [3_000, 0]]), new Money(1));

    expect(fifoAllocations($plan))->toBe([101 => 1])->and($plan->unallocatedCents)->toBe(0);
});

/**
 * RB-6 y `REQ-LAY-008` RB-3, RB-4: el saldo exacto cierra todas las cuotas; lo
 * que lo excede queda sin asignar y no se reparte en ninguna.
 */
it('cierra todas las cuotas con el saldo exacto y deja el excedente sin asignar', function (): void {
    $schedule = fifoSchedule([[3_000, 2_500], [3_000, 0], [3_001, 0]]);

    $exact = FifoAllocator::planFor($schedule, new Money(6_501));
    $over = FifoAllocator::planFor($schedule, new Money(10_000));

    expect(fifoAllocations($exact))->toBe([101 => 500, 102 => 3_000, 103 => 3_001])
        ->and($exact->unallocatedCents)->toBe(0)
        ->and(fifoAllocations($over))->toBe([101 => 500, 102 => 3_000, 103 => 3_001])
        ->and($over->unallocatedCents)->toBe(3_499);
});

/**
 * Sin nada que cubrir —cronograma saldado, o vacío porque el financiado es
 * cero (`REQ-LAY-004` SP-2)—, todo el pago queda sin asignar.
 */
it('no asigna nada si no queda nada por cubrir', function (array $installments): void {
    $plan = FifoAllocator::planFor(fifoSchedule($installments), new Money(3_000));

    expect($plan->allocations)->toBe([])->and($plan->unallocatedCents)->toBe(3_000);
})->with([
    'saldado' => [[[3_000, 3_000], [3_000, 3_000]]],
    'vacío' => [[]],
]);

/**
 * Una cuota con más pagado que su importe —no debería existir, pero un
 * reparto manual mal hecho la dejaría— no recibe nada ni resta del pago.
 */
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

/**
 * Un cronograma recién generado no tiene filas a las que asignar.
 */
it('no reparte sobre un cronograma sin materializar', function (): void {
    FifoAllocator::planFor(new AmortizationSchedule([new ScheduledInstallment(1, CarbonImmutable::parse('2026-09-25'), new Money(3_000))]), new Money(3_000));
})->throws(LogicException::class);

/**
 * **La propiedad, con quinientos casos.** Para cualquier cronograma y
 * cualquier pago positivo:
 *
 *  1. `sum(allocations) === importe − unallocated` (la invariante del gate);
 *  2. ninguna cuota recibe más de lo que le falta —nunca se sobrepaga—;
 *  3. ninguna cuota cubierta recibe nada;
 *  4. es FIFO: todas las asignaciones salvo la última cubren su cuota entera,
 *     y ninguna cuota con algo pendiente queda saltada antes de la última que
 *     recibió;
 *  5. solo sobra algo si ya no queda ninguna cuota por cubrir.
 *
 * La semilla es fija, como en `MoneyAllocationTest`: el caso 387 es el 387 en
 * la máquina de quien lo arregle. Y los tramos se eligen por lo que cada uno
 * puede romper, no por cubrir un rango.
 */
it('cumple las invariantes del reparto con 500 casos al azar', function (): void {
    mt_srand(20_261_004);

    $cases = [];

    // El caso corriente: cuotas iguales, nada pagado, pagos de una o varias
    // cuotas. Está para que la propiedad no se pruebe solo sobre lo raro.
    for ($i = 0; $i < 120; $i++) {
        $amount = mt_rand(1_000, 50_000);
        $count = mt_rand(1, 12);
        $cases[] = [array_fill(0, $count, [$amount, 0]), mt_rand(1, $amount * ($count + 1))];
    }

    // Cuotas pagadas y a medias intercaladas al azar: es donde un reparto que
    // usara el importe y no lo que falta sobrepagaría, y donde uno que no
    // saltara las cubiertas asignaría a una pagada.
    for ($i = 0; $i < 120; $i++) {
        $installments = [];

        foreach (range(1, mt_rand(1, 24)) as $ignored) {
            $amount = mt_rand(1, 40_000);
            $installments[] = [$amount, [0, $amount, mt_rand(0, $amount)][mt_rand(0, 2)]];
        }

        $cases[] = [$installments, mt_rand(1, 500_000)];
    }

    // Pagos que caen **exactamente** en el borde de una cuota (la suma de lo
    // pendiente hasta la k-ésima), y un centavo a cada lado: es donde un
    // `<` por `<=` deja una asignación de cero o se come un centavo.
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

    // Pagos que exceden todo lo pendiente —la liquidación con excedente de
    // `REQ-LAY-008` RB-4—: lo que sobra tiene que quedar sin asignar entero.
    for ($i = 0; $i < 70; $i++) {
        $amount = mt_rand(500, 20_000);
        $count = mt_rand(1, 10);
        $cases[] = [array_fill(0, $count, [$amount, mt_rand(0, $amount)]), $amount * $count + mt_rand(1, 100_000)];
    }

    // Cronogramas largos —36 cuotas, el máximo de `REQ-LAY-004` RB-7— con
    // centavos sueltos: el residuo del reparto vive en la última cuota.
    for ($i = 0; $i < 50; $i++) {
        $installments = array_map(fn (): array => [mt_rand(1, 9_999), 0], range(1, 36));
        $cases[] = [$installments, mt_rand(1, 400_000)];
    }

    // Pagos de un centavo y de pocos centavos.
    for ($i = 0; $i < 20; $i++) {
        $cases[] = [array_fill(0, mt_rand(1, 5), [mt_rand(1, 3), 0]), mt_rand(1, 3)];
    }

    expect($cases)->toHaveCount(500);

    foreach ($cases as $index => [$installments, $payment]) {
        $plan = FifoAllocator::planFor(fifoSchedule($installments), new Money($payment));
        $given = fifoAllocations($plan);
        $context = sprintf('caso %d: cuotas %s, pago %d', $index, json_encode($installments), $payment);

        // 1 · La invariante del gate.
        expect($plan->allocatedCents())->toBe($payment - $plan->unallocatedCents, $context);

        // Y todo lo asignado va a cuotas de este cronograma: sin esto, un
        // reparto a ids equivocados dejaría las demás propiedades en vacío.
        expect(array_diff(array_keys($given), range(101, 100 + count($installments))))->toBe([], $context);

        $lastAllocated = $plan->allocations === [] ? 0 : (int) array_key_last($given) - 100;

        foreach ($installments as $position => [$amount, $paid]) {
            $number = $position + 1;
            $pending = max(0, $amount - $paid);
            $received = $given[100 + $number] ?? 0;

            // 2 y 3 · Nunca más de lo que falta; nada a una cubierta.
            expect($received)->toBeLessThanOrEqual($pending, $context);

            // 4 · FIFO: antes de la última que recibió, toda cuota pendiente
            // queda cubierta entera.
            if ($number < $lastAllocated) {
                expect($received)->toBe($pending, $context);
            }
        }

        // 5 · Solo sobra si ya no queda nada por cubrir.
        if ($plan->unallocatedCents > 0) {
            $totalPending = array_sum(array_map(fn (array $row): int => max(0, $row[0] - $row[1]), $installments));
            expect($plan->allocatedCents())->toBe($totalPending, $context);
        }
    }
});
