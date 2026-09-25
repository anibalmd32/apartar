<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Una cuota del cronograma: cuál es, cuándo vence y cuánto (`REQ-LAY-004`).
 *
 * ## `dueDate` es un día, no un instante
 *
 * Una cuota vence **el día 25 en la zona de la organización**, no a una hora
 * (`REQ-ORG-006` RB-7). Se lleva como `CarbonImmutable` a las 00:00 de ese día
 * en la zona del negocio porque es el tipo de fecha del dominio, pero **lo
 * único que significa es su fecha de calendario**: la hora y la zona son
 * soporte, y quien la persista o la compare lo hace con `dueDateString()`. Es
 * la misma decisión que `installments.due_date` en `DATE` (`T-039`): «vencida»
 * se decide comparando días contra el «hoy» de la organización, nunca
 * instantes.
 *
 * ## Lo pagado viaja con la cuota
 *
 * Una cuota recién generada no tiene nada pagado, y así nace. Cuando el
 * cronograma se reconstruya desde `installments` con pagos aplicados,
 * `paid` es lo que permite a `AmortizationSchedule::firstUnpaid()` responder
 * lo que su nombre dice (`REQ-LAY-004` RB-8, `REQ-PAY-008` RB-3).
 */
final readonly class ScheduledInstallment
{
    public Money $paid;

    /**
     * @param  int  $number  posición en el cronograma, desde 1
     * @param  CarbonImmutable  $dueDate  el día de vencimiento, a las 00:00 en la zona de la organización
     * @param  Money  $amount  el importe, inmutable (RB-6)
     * @param  Money|null  $paid  lo pagado; nada si se omite
     */
    public function __construct(
        public int $number,
        public CarbonImmutable $dueDate,
        public Money $amount,
        ?Money $paid = null,
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException(sprintf('Las cuotas se numeran desde 1, y llegó la %d.', $number));
        }

        $this->paid = $paid ?? new Money(0, $amount->currency);
    }

    /**
     * El día de vencimiento, `Y-m-d`: lo que se guarda en `installments.due_date`.
     */
    public function dueDateString(): string
    {
        return $this->dueDate->toDateString();
    }

    /**
     * Si ya no se debe nada de ella.
     *
     * `>=` y no `===`: un excedente sobre una cuota no la deja a medias. El
     * reparto de un pago entre cuotas es de `FifoAllocator` (`T-056`), y nunca
     * sobrepaga una, pero esta pregunta no tiene por qué depender de eso.
     */
    public function isPaid(): bool
    {
        return $this->paid->cents >= $this->amount->cents;
    }
}
