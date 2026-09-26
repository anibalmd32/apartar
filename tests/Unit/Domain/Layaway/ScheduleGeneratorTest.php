<?php

declare(strict_types=1);

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\InvalidScheduleException;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Layaway\ScheduleGenerator;
use App\Domain\Money\Money;
use App\Rules\CoherentFinancingTerms;
use Carbon\CarbonImmutable;

/**
 * El cronograma de un apartado (`REQ-LAY-004`): cuántas cuotas, de cuánto y
 * para cuándo. Es lo que el cliente acepta al pulsar «Apartar».
 */
const CARACAS = 'America/Caracas';

function scheduleFor(
    int $unitPriceCents,
    int $downPaymentCents,
    int $installments,
    int $frequencyDays,
    string $activatedAt = '2026-09-10 14:00',
    string $timezone = CARACAS,
    int $quantity = 1,
): AmortizationSchedule {
    return (new ScheduleGenerator)->generate(
        new LayawayTerms(new Money($unitPriceCents), new Money($downPaymentCents), $quantity, $installments, $frequencyDays),
        CarbonImmutable::parse($activatedAt, $timezone),
        new DateTimeZone($timezone),
    );
}

/**
 * @return list<int>
 */
function amountsOf(AmortizationSchedule $schedule): array
{
    return array_map(fn (ScheduledInstallment $installment): int => $installment->amount->cents, $schedule->installments);
}

/**
 * @return list<string>
 */
function datesOf(AmortizationSchedule $schedule): array
{
    return array_map(fn (ScheduledInstallment $installment): string => $installment->dueDateString(), $schedule->installments);
}

/**
 * El *happy path* de RB-3 tal cual: $200 con inicial de $50 en cinco cuotas
 * quincenales, activado el 10/09 en Caracas.
 */
it('reparte y fecha el happy path del requisito', function (): void {
    $schedule = scheduleFor(20_000, 5_000, 5, 15);

    expect(amountsOf($schedule))->toBe([3_000, 3_000, 3_000, 3_000, 3_000])
        ->and(datesOf($schedule))->toBe(['2026-09-25', '2026-10-10', '2026-10-25', '2026-11-09', '2026-11-24'])
        ->and(array_map(fn (ScheduledInstallment $i): int => $i->number, $schedule->installments))->toBe([1, 2, 3, 4, 5]);
});

/**
 * RB-2 y SP-1: `$100 / 3` no son tres cuotas de `3333`, porque el céntimo que
 * falta es dinero de alguien. Va en la **última**.
 */
it('deja el residuo en la última cuota', function (): void {
    $schedule = scheduleFor(10_000, 0, 3, 15);

    expect(amountsOf($schedule))->toBe([3_333, 3_333, 3_334])
        ->and($schedule->totalAmount()->cents)->toBe(10_000)
        ->and($schedule->last()?->amount->cents)->toBe(3_334);
});

/**
 * RB-3: la cuota N vence a los `N × frequencyDays` días de la activación, y
 * la 1 **no** vence el mismo día.
 */
it('fecha las cuotas en días exactos de calendario', function (int $frequency, array $expected): void {
    expect(datesOf(scheduleFor(40_000, 0, 4, $frequency)))->toBe($expected);
})->with([
    'semanal' => [7, ['2026-09-17', '2026-09-24', '2026-10-01', '2026-10-08']],
    'quincenal' => [15, ['2026-09-25', '2026-10-10', '2026-10-25', '2026-11-09']],
    'cada 30 días' => [30, ['2026-10-10', '2026-11-09', '2026-12-09', '2027-01-08']],
]);

/**
 * SP-4: la frecuencia es en días, no en meses. El 29 de febrero y los fines de
 * mes no son casos especiales, y nada se desplaza por domingo (RB-4): el
 * 2027-03-02 es martes y el 2028-03-01 miércoles, pero el 2026-09-27 es
 * domingo y ahí se queda.
 */
it('no trata como especiales los fines de mes, el bisiesto ni el domingo', function (): void {
    expect(datesOf(scheduleFor(10_000, 0, 1, 30, '2027-01-31 09:00')))->toBe(['2027-03-02'])
        ->and(datesOf(scheduleFor(10_000, 0, 1, 30, '2028-01-31 09:00')))->toBe(['2028-03-01'])
        ->and(datesOf(scheduleFor(10_000, 0, 2, 1, '2028-02-28 09:00')))->toBe(['2028-02-29', '2028-03-01'])
        ->and(datesOf(scheduleFor(10_000, 0, 1, 1, '2026-09-26 09:00')))->toBe(['2026-09-27']);
});

/**
 * SP-7: el mismo instante es el día 9 en Caracas y el 10 en Madrid, y ese día
 * de diferencia se arrastra a todas las cuotas. Las fechas se calculan en la
 * zona del negocio, no en UTC ni en la del servidor.
 */
it('da fechas distintas a dos organizaciones en zonas distintas que activan en el mismo instante', function (): void {
    $instant = CarbonImmutable::parse('2026-09-10 02:00', 'UTC');
    $terms = new LayawayTerms(new Money(20_000), new Money(5_000), 1, 3, 15);
    $generator = new ScheduleGenerator;

    $caracas = $generator->generate($terms, $instant, new DateTimeZone(CARACAS));
    $madrid = $generator->generate($terms, $instant, new DateTimeZone('Europe/Madrid'));

    expect(datesOf($caracas))->toBe(['2026-09-24', '2026-10-09', '2026-10-24'])
        ->and(datesOf($madrid))->toBe(['2026-09-25', '2026-10-10', '2026-10-25'])
        ->and(amountsOf($caracas))->toBe(amountsOf($madrid));
});

/**
 * En una zona con horario de verano, un día de 23 horas sigue siendo un día.
 * Santiago de Chile adelanta el reloj a medianoche del primer domingo de
 * septiembre: el 2026-09-06 no tiene 00:00, y la cuota que vence ese día tiene
 * que vencer ese día y no el 5 ni el 7.
 */
it('no corre la fecha cuando cruza un cambio de horario', function (): void {
    expect(datesOf(scheduleFor(30_000, 0, 3, 7, '2026-08-30 23:30', 'America/Santiago')))
        ->toBe(['2026-09-06', '2026-09-13', '2026-09-20']);
});

/**
 * SP-2: la inicial es el precio entero y no queda nada que financiar. No es un
 * error: es un cronograma vacío.
 */
it('devuelve un cronograma vacío sin lanzar cuando el financiado es cero', function (): void {
    $schedule = scheduleFor(15_000, 15_000, 5, 15, quantity: 3);

    expect($schedule->isEmpty())->toBeTrue()
        ->and($schedule->count())->toBe(0)
        ->and($schedule->totalAmount()->cents)->toBe(0)
        ->and($schedule->firstUnpaid())->toBeNull()
        ->and($schedule->last())->toBeNull();
});

/**
 * SP-3: una cuota es todo el financiado, a los `frequencyDays`. Es el caso que
 * rompe un bucle que empiece en 1 o termine en `count - 1`.
 */
it('pone todo el financiado en la única cuota', function (): void {
    $schedule = scheduleFor(12_345, 2_345, 1, 20);

    expect(amountsOf($schedule))->toBe([10_000])
        ->and(datesOf($schedule))->toBe(['2026-09-30'])
        ->and($schedule->firstUnpaid()?->number)->toBe(1);
});

/**
 * RB-8: recién generado, lo primero que se debe es la cuota 1, y su día es
 * `layaways.next_due_date`.
 */
it('señala la primera cuota como la próxima que vence', function (): void {
    expect(scheduleFor(20_000, 5_000, 5, 15)->firstUnpaid()?->dueDateString())->toBe('2026-09-25');
});

/**
 * §5 del prompt: **reparte el total, nunca multipliques un reparto**. Dos
 * unidades con $50 financiados cada una en tres cuotas son $100 en tres
 * cuotas. Multiplicar las de una unidad da otra cifra, porque
 * `allocateEvenly()` no es lineal.
 */
it('reparte el financiado del apartado entero y no multiplica el de una unidad', function (): void {
    $schedule = scheduleFor(6_000, 1_000, 3, 15, quantity: 2);

    $perUnitTimesTwo = array_map(
        fn (Money $amount): int => $amount->multiply(2)->cents,
        (new FinancingPlan(new Money(6_000), new Money(1_000), 3, 15))->installments(),
    );

    expect(amountsOf($schedule))->toBe([3_333, 3_333, 3_334])
        ->and($perUnitTimesTwo)->toBe([3_332, 3_332, 3_336])
        ->and(amountsOf($schedule))->not->toBe($perUnitTimesTwo);
});

/**
 * **El caso que dejó `T-035`**: la cuota que enseña la ficha del producto la
 * reparte `FinancingPlan`, y la del cronograma tiene que ser la misma **hasta
 * el céntimo, con el residuo en la misma cuota**, cuando se aparta una unidad.
 * Se construye el plan como lo construye `ProductDetailResource` —
 * `FinancingPlan::tryFrom()` con las columnas del producto— y se compara
 * importe a importe sobre condiciones al azar con semilla fija.
 */
it('coincide al céntimo con la cuota de la ficha cuando se aparta una unidad', function (): void {
    mt_srand(20_260_925);

    for ($case = 0; $case < 200; $case++) {
        $price = mt_rand(1, CoherentFinancingTerms::MAX_PRICE_CENTS);
        $down = mt_rand(0, $price - 1);
        $count = mt_rand(ScheduleGenerator::MIN_INSTALLMENTS, ScheduleGenerator::MAX_INSTALLMENTS);
        $frequency = mt_rand(ScheduleGenerator::MIN_FREQUENCY_DAYS, ScheduleGenerator::MAX_FREQUENCY_DAYS);

        $plan = FinancingPlan::tryFrom(new Money($price), new Money($down), $count, $frequency);
        $schedule = scheduleFor($price, $down, $count, $frequency);
        $label = "precio {$price}, inicial {$down}, {$count} cuotas";

        expect($plan)->not->toBeNull($label);
        expect(amountsOf($schedule))->toBe(array_map(fn (Money $m): int => $m->cents, $plan?->installments() ?? []), $label);
        expect($schedule->installments[0]->amount->cents)->toBe($plan?->installmentAmount()->cents, $label);
        expect($schedule->last()?->amount->cents)->toBe($plan?->lastInstallmentAmount()->cents, $label);
    }
});

/**
 * RB-5, la invariante dura: `sum(cuotas) === total − inicial total`,
 * **exactamente**, con 500 condiciones al azar. `allocateEvenly()` ya lo
 * garantiza por construcción; esto existe para que siga siendo verdad el día
 * que alguien lo «optimice».
 *
 * La semilla es fija, que es la convención del proyecto: lo que se congela es
 * el azar, no el calendario. Y los tramos se eligen por lo que cada uno puede
 * romper:
 *
 *  - **precios corrientes, una unidad**: el caso que ya funciona, para que la
 *    propiedad no se pruebe solo sobre lo raro;
 *  - **varias unidades**: donde vive la trampa de multiplicar un reparto;
 *  - **financiados primos**: donde vive el residuo;
 *  - **financiado igual a las cuotas**: un céntimo por cuota, el borde de la
 *    séptima regla (`T-045`). Por debajo —cuotas de cero— ya no se puede
 *    construir un `LayawayTerms`, y eso lo afirma el caso siguiente;
 *  - **inicial igual al precio**: el cronograma vacío de SP-2.
 */
it('cumple la invariante de la suma con 500 condiciones al azar', function (): void {
    mt_srand(20_260_926);

    $cases = [];

    for ($i = 0; $i < 150; $i++) {
        $price = mt_rand(100, 500_000);
        $cases[] = [$price, mt_rand(0, $price - 1), 1, null];
    }

    for ($i = 0; $i < 150; $i++) {
        $price = mt_rand(100, 500_000);
        $cases[] = [$price, mt_rand(0, $price - 1), mt_rand(2, 10), null];
    }

    foreach ([97, 1_009, 10_007, 99_991, 1_000_003] as $prime) {
        for ($i = 0; $i < 20; $i++) {
            $cases[] = [$prime + 500, 500, 1, null];
        }
    }

    for ($i = 0; $i < 60; $i++) {
        $financed = mt_rand(ScheduleGenerator::MIN_INSTALLMENTS, ScheduleGenerator::MAX_INSTALLMENTS);
        $cases[] = [$financed + 100, 100, 1, $financed];
    }

    for ($i = 0; $i < 40; $i++) {
        $price = mt_rand(1, 500_000);
        $cases[] = [$price, $price, mt_rand(1, 10), null];
    }

    expect($cases)->toHaveCount(500);

    foreach ($cases as [$price, $down, $quantity, $fixedCount]) {
        $count = $fixedCount ?? mt_rand(ScheduleGenerator::MIN_INSTALLMENTS, ScheduleGenerator::MAX_INSTALLMENTS);
        $frequency = mt_rand(ScheduleGenerator::MIN_FREQUENCY_DAYS, ScheduleGenerator::MAX_FREQUENCY_DAYS);
        $activation = CarbonImmutable::createFromTimestamp(mt_rand(1_700_000_000, 1_900_000_000), 'UTC');

        $terms = new LayawayTerms(new Money($price), new Money($down), $quantity, $count, $frequency);
        $schedule = (new ScheduleGenerator)->generate($terms, $activation, new DateTimeZone(CARACAS));
        $financed = ($price - $down) * $quantity;
        $label = "precio {$price}, inicial {$down}, {$quantity} uds., {$count} cuotas cada {$frequency} días";

        expect(array_sum(amountsOf($schedule)))->toBe($financed, $label)
            ->and($schedule->totalAmount()->cents)->toBe($financed, $label)
            ->and($schedule->count())->toBe($financed === 0 ? 0 : $count, $label);

        if ($financed === 0) {
            continue;
        }

        // Todas iguales salvo la última, que lleva el residuo.
        $base = intdiv($financed, $count);
        expect(array_slice(amountsOf($schedule), 0, -1))->each->toBe($base)
            ->and($schedule->last()?->amount->cents)->toBe($financed - $base * ($count - 1), $label);

        // Y cada cuota a `frequency` días de la anterior, en el calendario de
        // la zona.
        $day = $activation->setTimezone(CARACAS)->startOfDay();
        foreach ($schedule->installments as $installment) {
            $day = $day->addDays($frequency);
            expect($installment->dueDateString())->toBe($day->toDateString(), $label);
        }
    }
});

/**
 * La séptima regla como guarda (`T-045`): un financiado menor que el número
 * de cuotas dejaría cuotas de $0,00, que la cobranza trataría como vencidas.
 * `CoherentFinancingTerms` lo impide al guardar el producto; aquí no se puede
 * ni construir.
 */
it('no congela unas condiciones con cuotas de cero', function (int $financed, int $count): void {
    expect(fn () => new LayawayTerms(new Money($financed + 100), new Money(100), 1, $count, 15))
        ->toThrow(InvalidScheduleException::class, 'alguna saldría de $0,00');
})->with([
    '$0,05 en 36' => [5, 36],
    'un céntimo de menos' => [35, 36],
    '$0,01 en 2' => [1, 2],
]);

/**
 * §8.4: dominio puro. Con el reloj congelado en dos fechas que no se parecen,
 * el cronograma es el mismo, porque nada dentro pregunta qué día es.
 */
it('no depende del día en que se genera', function (): void {
    CarbonImmutable::setTestNow('1999-12-31 23:59');
    $then = datesOf(scheduleFor(20_000, 5_000, 5, 15));

    CarbonImmutable::setTestNow('2040-06-15 12:00');
    $later = datesOf(scheduleFor(20_000, 5_000, 5, 15));

    CarbonImmutable::setTestNow();

    expect($then)->toBe($later)->toBe(['2026-09-25', '2026-10-10', '2026-10-25', '2026-11-09', '2026-11-24']);
});

/**
 * Y lo mismo dicho sobre el código: ni reloj, ni configuración, ni tenant, ni
 * base de datos. Si alguien añade un `now()` para «ahorrar un parámetro», esto
 * lo dice antes que un cronograma que cambia según el día.
 */
it('no toca reloj, configuración, tenant ni base de datos', function (string $forbidden): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/Domain/Layaway/ScheduleGenerator.php');
    $code = implode('', array_map(
        fn (array|string $token): string => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
        token_get_all($source),
    ));

    expect($code)->not->toContain($forbidden);
})->with(['now(', 'today(', 'config(', 'TenantContext', 'DB::', 'Illuminate\\', 'App\\Models']);
