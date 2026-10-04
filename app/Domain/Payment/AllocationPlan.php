<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use InvalidArgumentException;

/**
 * Cómo se reparte un importe entre las cuotas de un apartado
 * (`REQ-PAY-005` RB-4, RB-6; `REQ-PAY-009`).
 *
 * Lo produce `FifoAllocator` (`T-056`) y lo escribe `PaymentApplier`
 * (`T-058`) como filas de `payment_allocations`; la reasignación (`T-063`)
 * construye uno a mano. Es dominio puro: ids de cuota y centavos de USD.
 *
 * ## Lo que no se puede construir
 *
 *  - **Una asignación de cero o negativa.** Una fila de reparto de cero
 *    centavos no dice nada y estropea la suma por cuota.
 *  - **Dos asignaciones a la misma cuota.** El reparto es una por cuota;
 *    partirla en dos filas es la forma de que una suma de comprobación cuente
 *    lo mismo dos veces.
 *  - **Un sobrante negativo.** Lo que no se asigna (RB-6) es cero o más:
 *    nunca un crédito al revés.
 *
 * Lo que este tipo **no** sabe es si una cuota quedaría sobrepagada: no
 * conoce sus importes. Eso lo garantiza quien lo construye —`FifoAllocator`
 * por construcción, la reasignación comprobándolo (`REQ-PAY-009` SP-3)—.
 */
final readonly class AllocationPlan
{
    /**
     * @param  list<array{installmentId: int, cents: int}>  $allocations  en el orden en que se cubren
     * @param  int  $unallocatedCents  lo que no se asignó: no genera crédito (RB-6)
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public array $allocations,
        public int $unallocatedCents,
    ) {
        $seen = [];

        foreach ($allocations as $allocation) {
            if ($allocation['cents'] <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'Una asignación es de más de cero centavos, y a la cuota %d se le asignaron %d.',
                    $allocation['installmentId'],
                    $allocation['cents'],
                ));
            }

            if (isset($seen[$allocation['installmentId']])) {
                throw new InvalidArgumentException(sprintf(
                    'La cuota %d aparece dos veces en el reparto: es una asignación por cuota.',
                    $allocation['installmentId'],
                ));
            }

            $seen[$allocation['installmentId']] = true;
        }

        if ($unallocatedCents < 0) {
            throw new InvalidArgumentException(sprintf('Lo no asignado no puede ser negativo, y es %d.', $unallocatedCents));
        }
    }

    /**
     * Lo repartido entre cuotas.
     */
    public function allocatedCents(): int
    {
        return array_sum(array_column($this->allocations, 'cents'));
    }

    /**
     * Si el importe entero quedó asignado, sin sobrante.
     */
    public function isFullyAllocated(): bool
    {
        return $this->unallocatedCents === 0;
    }

    /**
     * El importe que se repartió: lo asignado más lo que sobró. Es el lado
     * izquierdo de la invariante `sum(allocations) === importe − unallocated`
     * (`T-056`).
     */
    public function totalCents(): int
    {
        return $this->allocatedCents() + $this->unallocatedCents;
    }

    /**
     * Lo asignado a esa cuota, o cero si no recibió nada.
     */
    public function centsFor(int $installmentId): int
    {
        foreach ($this->allocations as $allocation) {
            if ($allocation['installmentId'] === $installmentId) {
                return $allocation['cents'];
            }
        }

        return 0;
    }
}
