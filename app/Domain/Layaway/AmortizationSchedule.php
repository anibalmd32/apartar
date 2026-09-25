<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * El cronograma de un apartado: sus cuotas, en orden (`REQ-LAY-004`).
 *
 * **Puede estar vacío, y eso es un cronograma, no un error.** Un apartado cuya
 * inicial es el precio entero no deja nada por financiar (SP-2): no tiene
 * cuotas, `totalAmount()` es cero y `firstUnpaid()` es `null`, que es
 * exactamente el `next_due_date = null` de `REQ-PAY-008` SP-3. Qué hace el
 * apartado entonces —pasar a `completed`— lo deciden la máquina de estados
 * (`T-042`) y la activación (`T-046`), no esta clase.
 *
 * Por eso lleva la moneda aparte: un cronograma vacío no tiene ninguna cuota
 * de la que sacarla, y `totalAmount()` tiene que devolver un cero **en
 * dólares**, no un cero de ninguna parte.
 */
final readonly class AmortizationSchedule
{
    /**
     * @param  list<ScheduledInstallment>  $installments  numeradas 1…n, en ese orden
     *
     * @throws InvalidArgumentException si la numeración salta o se repite, o hay otra moneda
     */
    public function __construct(
        public array $installments,
        public Currency $currency = Currency::USD,
    ) {
        foreach ($installments as $position => $installment) {
            if ($installment->number !== $position + 1) {
                throw new InvalidArgumentException(sprintf(
                    'Las cuotas de un cronograma van numeradas 1…n y en orden, y en la posición %d está la %d. '
                    .'Es la numeración que protege UNIQUE(organization_id, layaway_id, number).',
                    $position + 1,
                    $installment->number,
                ));
            }

            if ($installment->amount->currency !== $currency) {
                throw new InvalidArgumentException(sprintf(
                    'La cuota %d está en %s y el cronograma en %s.',
                    $installment->number,
                    $installment->amount->currency->value,
                    $currency->value,
                ));
            }
        }
    }

    public function count(): int
    {
        return count($this->installments);
    }

    public function isEmpty(): bool
    {
        return $this->installments === [];
    }

    /**
     * La suma de las cuotas: el financiado exacto, al céntimo (RB-5).
     *
     * Es la mitad de cronograma de la invariante que `layaways:reconcile`
     * comprueba contra `total_cents − totalDownPayment`. Cero si no hay
     * cuotas.
     */
    public function totalAmount(): Money
    {
        $total = new Money(0, $this->currency);

        foreach ($this->installments as $installment) {
            $total = $total->add($installment->amount);
        }

        return $total;
    }

    /**
     * La primera cuota que aún se debe, o `null` si no queda ninguna
     * (RB-8, `REQ-PAY-008` RB-3).
     *
     * Su día es `layaways.next_due_date`. Recién generado, es la cuota 1; en
     * un cronograma vacío o saldado, `null`.
     */
    public function firstUnpaid(): ?ScheduledInstallment
    {
        foreach ($this->installments as $installment) {
            if (! $installment->isPaid()) {
                return $installment;
            }
        }

        return null;
    }

    /**
     * La última, que es la que lleva el residuo del reparto (RB-2), o `null`
     * si no hay cuotas.
     */
    public function last(): ?ScheduledInstallment
    {
        return $this->installments === [] ? null : $this->installments[count($this->installments) - 1];
    }
}
