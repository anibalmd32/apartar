<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Models\Payment;
use App\Models\PaymentAllocation;

final class PaymentConfirmation
{
    public static function of(Payment $payment): string
    {
        $payment->loadMissing('allocations.installment');
        $allocated = (int) $payment->allocations->sum(fn (PaymentAllocation $allocation): int => $allocation->amount_cents->cents);
        $numbers = $payment->allocations->map(fn (PaymentAllocation $allocation): ?int => $allocation->installment?->number)->filter()->sort()->values()->all();
        $downPayment = $payment->amount_cents_usd->cents - $allocated - $payment->unallocated_cents->cents;

        $parts = [];

        if ($downPayment > 0) {
            $parts[] = sprintf('inicial de %s', MoneyFormatter::format(new Money($downPayment)));
        }

        if ($numbers !== []) {
            $parts[] = sprintf('%s a %s', MoneyFormatter::format(new Money($allocated)), count($numbers) === 1
                ? sprintf('la cuota %d', $numbers[0])
                : sprintf('las cuotas %s y %d', implode(', ', array_slice($numbers, 0, -1)), $numbers[count($numbers) - 1]));
        }

        if ($payment->unallocated_cents->cents > 0) {
            $parts[] = sprintf('%s sin asignar', MoneyFormatter::format($payment->unallocated_cents));
        }

        return sprintf('Pago registrado: %s (%s).', MoneyFormatter::format($payment->amount_cents_usd), implode('; ', $parts));
    }
}
