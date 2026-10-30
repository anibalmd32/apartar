<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\DownPaymentLedger;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Models\Layaway;
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

        $downPaymentLabel = self::downPaymentOf($payment, $downPayment)->label();

        if ($downPaymentLabel !== null) {
            $parts[] = $downPaymentLabel;
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

    private static function downPaymentOf(Payment $payment, int $applied): DownPaymentProgress
    {
        if ($applied <= 0 || ! $payment->exists) {
            return new DownPaymentProgress(max(0, $applied));
        }

        $layaway = Layaway::query()->find($payment->layaway_id);

        if (! $layaway instanceof Layaway) {
            return new DownPaymentProgress($applied);
        }

        $required = $layaway->terms()->totalDownPayment()->cents;
        $through = min($required, app(DownPaymentLedger::class)->paidThrough($payment));

        return new DownPaymentProgress($applied, max(0, $through - $applied), $required - $through);
    }
}
