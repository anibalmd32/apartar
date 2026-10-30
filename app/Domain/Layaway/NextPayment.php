<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Layaway;
use InvalidArgumentException;

final readonly class NextPayment
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public NextPaymentKind $kind,
        public ?int $installmentNumber,
        public Money $amount,
        public bool $started = false,
    ) {
        if ($amount->cents <= 0) {
            throw new InvalidArgumentException(sprintf('Un pago sugerido es mayor que cero, y es de %d centavos.', $amount->cents));
        }

        if (($kind === NextPaymentKind::Installment) !== ($installmentNumber !== null)) {
            throw new InvalidArgumentException('Una cuota sugerida dice qué número es, y la inicial no lo dice.');
        }
    }

    /**
     * @param  list<Installment>  $installments
     */
    public static function for(Layaway $layaway, array $installments): ?self
    {
        if (! $layaway->status->acceptsPayments()) {
            return null;
        }

        if ($layaway->status === LayawayStatus::PendingDownPayment) {
            $downPayment = $layaway->terms()->totalDownPayment()->cents;
            $paid = min($downPayment, $layaway->paid_cents->cents);

            return $downPayment > $paid ? new self(NextPaymentKind::DownPayment, null, new Money($downPayment - $paid), $paid > 0) : null;
        }

        foreach ($installments as $installment) {
            $pending = $installment->amount_cents->cents - $installment->paid_cents->cents;

            if ($pending > 0) {
                return new self(NextPaymentKind::Installment, $installment->number, new Money($pending), $installment->paid_cents->cents > 0);
            }
        }

        return null;
    }
}
