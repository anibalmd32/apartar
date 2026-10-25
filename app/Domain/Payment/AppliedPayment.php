<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Payment;
use InvalidArgumentException;

final readonly class AppliedPayment
{
    /**
     * @throws InvalidArgumentException
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
                .'amount === inicial + asignaciones + sobrante es una invariante dura.',
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

    public function activated(): bool
    {
        return $this->downPaymentCents > 0;
    }
}
