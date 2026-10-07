<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Layaway;
use InvalidArgumentException;

/**
 * Cuánto le toca pagar ahora al cliente (`REQ-LAY-021` RB-9, `REQ-PAY-001`
 * RB-9): la inicial si el apartado la espera, o lo que le falta a la cuota
 * más antigua sin cubrir.
 *
 * La enseñan dos pantallas —el detalle del apartado y el formulario del
 * comprobante, que precarga el importe con ella— y tienen que sugerir lo
 * mismo. Estaba escrita dentro de `LayawayShowController` hasta `T-059`; un
 * segundo censo de «qué toca pagar» en el formulario habría podido sugerir la
 * cuota 3 donde el detalle dice la 2.
 *
 * Es una sugerencia, no una regla: el cliente puede pagar más, menos o el
 * saldo entero, y el reparto real lo hace `FifoAllocator` al aprobar.
 */
final readonly class NextPayment
{
    /**
     * @throws InvalidArgumentException si el importe no es positivo o el tipo no casa con el número de cuota
     */
    public function __construct(
        public NextPaymentKind $kind,
        public ?int $installmentNumber,
        public Money $amount,
    ) {
        if ($amount->cents <= 0) {
            throw new InvalidArgumentException(sprintf('Un pago sugerido es mayor que cero, y es de %d centavos.', $amount->cents));
        }

        if (($kind === NextPaymentKind::Installment) !== ($installmentNumber !== null)) {
            throw new InvalidArgumentException('Una cuota sugerida dice qué número es, y la inicial no lo dice.');
        }
    }

    /**
     * Lo que toca pagar en ese apartado con esas cuotas, o `null` si no
     * cobra o no queda nada por cubrir.
     *
     * @param  list<Installment>  $installments  las del apartado, en orden
     */
    public static function for(Layaway $layaway, array $installments): ?self
    {
        if (! $layaway->status->acceptsPayments()) {
            return null;
        }

        if ($layaway->status === LayawayStatus::PendingDownPayment) {
            $downPayment = $layaway->terms()->totalDownPayment();

            // Una inicial de cero es legal (`REQ-BOF-020`: `down_payment >= 0`)
            // y no es un pago que sugerir: la sugerencia es una ayuda, y una
            // de $0,00 no ayuda a nadie. Sin cronograma todavía, no hay más.
            return $downPayment->cents > 0 ? new self(NextPaymentKind::DownPayment, null, $downPayment) : null;
        }

        foreach ($installments as $installment) {
            $pending = $installment->amount_cents->cents - $installment->paid_cents->cents;

            if ($pending > 0) {
                return new self(NextPaymentKind::Installment, $installment->number, new Money($pending));
            }
        }

        return null;
    }
}
