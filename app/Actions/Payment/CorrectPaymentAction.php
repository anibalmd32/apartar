<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Layaway\StaffReason;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentAttachment;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\PaymentNotVoidableException;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentSafeguard;
use App\Models\Receipt;
use App\Models\User;
use App\Support\ModelState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class CorrectPaymentAction
{
    public function __construct(
        private readonly VoidPaymentAction $void,
        private readonly RegisterManualPaymentAction $manual,
        private readonly ApproveReceiptAction $approve,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws PaymentNotVoidableException
     * @throws ReceiptAlreadyProcessedException
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     * @throws UnconfirmedExcessException
     * @throws IdempotencyKeyReusedException
     * @throws UnconfirmedStaleRateException
     * @throws UnconfirmedRateDeviationException
     * @throws DuplicateReceiptException
     */
    public function handle(Payment $payment, Layaway $target, ManualPaymentData $data, User $actor): Payment
    {
        $source = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($actor)->authorize('manage', $source);
        Gate::forUser($actor)->authorize('manage', $target);

        $moving = (int) $target->getKey() !== (int) $source->getKey();
        $reason = (new StaffReason($data->note ?? ''))->text;
        $reason = $moving ? sprintf('Se movió a %s: %s', $target->code, $reason) : sprintf('Se corrigió: %s', $reason);

        return DB::transaction(function () use ($payment, $target, $data, $actor, $moving, $reason): Payment {
            $this->void->handle($payment, $actor, mb_substr($reason, 0, 500));

            return $this->linkReplacement($payment, $this->reapply($payment, $target, $data, $actor, $moving));
        });
    }

    private function linkReplacement(Payment $voided, Payment $replacement): Payment
    {
        Payment::query()->whereKey($replacement->getKey())->update(['replaces_payment_id' => $voided->getKey()]);
        PaymentSafeguard::query()->where('payment_id', $voided->getKey())->update(['payment_id' => $replacement->getKey()]);

        ModelState::adopt($replacement, ['replaces_payment_id' => $voided->getKey()] + $replacement->getAttributes());

        return $replacement;
    }

    /**
     * @throws AuthorizationException
     * @throws PaymentNotApplicableException
     * @throws ReceiptAlreadyProcessedException
     * @throws MissingExchangeRateException
     * @throws UnconfirmedExcessException
     * @throws IdempotencyKeyReusedException
     * @throws UnconfirmedStaleRateException
     * @throws UnconfirmedRateDeviationException
     * @throws DuplicateReceiptException
     */
    private function reapply(Payment $payment, Layaway $target, ManualPaymentData $data, User $actor, bool $moving): Payment
    {
        $receipt = $payment->receipt_id === null ? null : Receipt::query()->whereKey($payment->receipt_id)->lockForUpdate()->first();

        if ($receipt instanceof Receipt) {
            if ($moving) {
                $receipt->forceFill(['layaway_id' => $target->getKey(), 'customer_id' => $target->customer_id])->save();
            }

            return $this->approve->handle($receipt, new ApproveReceiptData(
                settledAmount: $data->amount,
                settledCurrency: $data->currency,
                rateOverride: $data->rateOverride,
                note: $data->note,
                confirmedExcessCents: $data->confirmedExcessCents,
                confirmedStaleRate: $data->confirmedStaleRate,
                confirmedRateDeviation: $data->confirmedRateDeviation,
            ), $actor)->payment;
        }

        return $this->manual->handle($target, $this->keepingAttachment($data, $payment), $actor, notifyCustomer: $moving);
    }

    private function keepingAttachment(ManualPaymentData $data, Payment $payment): ManualPaymentData
    {
        if ($data->file !== null || ! $payment->hasAttachment()) {
            return $data;
        }

        return new ManualPaymentData(
            method: $data->method,
            paymentAccount: $data->paymentAccount,
            amount: $data->amount,
            currency: $data->currency,
            rateOverride: $data->rateOverride,
            paidAt: $data->paidAt,
            note: $data->note,
            idempotencyKey: $data->idempotencyKey,
            confirmedExcessCents: $data->confirmedExcessCents,
            confirmedStaleRate: $data->confirmedStaleRate,
            confirmedRateDeviation: $data->confirmedRateDeviation,
            bank: $data->bank,
            reference: $data->reference,
            keptAttachment: new PaymentAttachment((string) $payment->file_path, (string) $payment->file_hash, (string) $payment->file_mime, (int) $payment->file_size),
        );
    }
}
