<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Payment;
use InvalidArgumentException;

/**
 * Lo que dejó aplicar un pago a un apartado ([05 §5], `T-058`): el pago
 * creado, cómo se repartió, y el estado antes y después.
 *
 * ## Dice en qué se fue cada centavo
 *
 * Un pago se reparte en tres partes, y las tres están aquí:
 *
 *  - **la inicial** (`$downPaymentCents`), si el pago activó el apartado. No es
 *    una cuota (`T-056`), así que no es una asignación ni es sobrante;
 *  - **las asignaciones** del `AllocationPlan`, una por cuota que cubrió;
 *  - **el sobrante** (`AllocationPlan::$unallocatedCents`), lo que no cupo en
 *    el saldo, que no es crédito (`REQ-PAY-005` RB-6) y queda guardado en
 *    `payments.unallocated_cents`.
 *
 * `amount = inicial + sum(asignaciones) + sobrante`, y no se puede construir
 * uno que no cuadre: es la cuenta que la nota de `T-058` pedía que
 * `AppliedPayment` supiera decir, y la mitad por pago de la segunda
 * invariante de `REQ-PAY-008` RB-3.
 */
final readonly class AppliedPayment
{
    /**
     * @throws InvalidArgumentException si las tres partes no suman el importe del pago, o el sobrante no es el guardado
     */
    public function __construct(
        public Payment $payment,
        public AllocationPlan $plan,
        public LayawayStatus $statusBefore,
        public LayawayStatus $statusAfter,
        public int $downPaymentCents = 0,
    ) {
        if ($downPaymentCents < 0) {
            throw new InvalidArgumentException(sprintf('La inicial aplicada no puede ser negativa, y es %d.', $downPaymentCents));
        }

        $amount = $payment->amount_cents_usd->cents;
        $parts = $downPaymentCents + $plan->totalCents();

        if ($parts !== $amount) {
            throw new InvalidArgumentException(sprintf(
                'Un pago de %d centavos se repartió en %d: %d de inicial, %d asignados y %d sin asignar. '
                .'amount === inicial + asignaciones + sobrante es una invariante dura (REQ-PAY-008 RB-3).',
                $amount,
                $parts,
                $downPaymentCents,
                $plan->allocatedCents(),
                $plan->unallocatedCents,
            ));
        }

        if ($payment->unallocated_cents->cents !== $plan->unallocatedCents) {
            throw new InvalidArgumentException(sprintf(
                'El pago guarda %d centavos sin asignar y el reparto dice %d.',
                $payment->unallocated_cents->cents,
                $plan->unallocatedCents,
            ));
        }
    }

    /**
     * Si este pago fue el que activó el apartado.
     */
    public function activated(): bool
    {
        return $this->downPaymentCents > 0;
    }
}
