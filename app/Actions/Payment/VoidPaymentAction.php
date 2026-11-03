<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\DownPaymentLedger;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentNotVoidableException;
use App\Domain\Payment\ReceiptStatus;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class VoidPaymentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly DetectOverdueAction $overdue,
        private readonly LayawayBalanceRecalculator $balance,
        private readonly LayawayStateMachine $machine,
        private readonly DownPaymentLedger $downPayments,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws PaymentNotVoidableException
     */
    public function handle(Payment $payment, User $actor, string $reason): Payment
    {
        $layaway = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($actor)->authorize('manage', $layaway);
        $motive = new StaffReason($reason);

        return DB::transaction(function () use ($payment, $actor, $motive): Payment {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($payment->layaway_id)->lockForUpdate()->firstOrFail();

            /** @var array<int, Installment> $installments */
            $installments = $locked->installments()->lockForUpdate()->get()->keyBy('id')->all();

            /** @var Payment $voiding */
            $voiding = Payment::query()->withVoided()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($voiding->isVoided()) {
                throw PaymentNotVoidableException::alreadyVoided();
            }

            /** @var array<int, int> $allocations */
            $allocations = PaymentAllocation::query()->where('payment_id', $voiding->getKey())->lockForUpdate()->get()
                ->mapWithKeys(fn (PaymentAllocation $allocation): array => [(int) $allocation->installment_id => $allocation->amount_cents->cents])
                ->all();

            $downPayment = $voiding->amount_cents_usd->cents - $voiding->unallocated_cents->cents - array_sum($allocations);
            $statusBefore = $locked->status;
            $paidBefore = $locked->paid_cents->cents;

            $this->keepDownPaymentCovered($locked, $downPayment);

            PaymentAllocation::query()->where('payment_id', $voiding->getKey())->delete();
            $this->resettle($installments, $allocations);

            $voiding->forceFill([
                'voided_at' => CarbonImmutable::now(),
                'voided_by' => $actor->getKey(),
                'void_reason' => $motive->text,
            ])->save();

            $receiptStatus = $this->returnReceiptToReview($voiding);

            $this->balance->recalculate($locked);
            $this->reopenIfOwing($locked, $statusBefore, $installments);

            $this->audit(
                AuditEvent::PaymentVoided,
                subject: $voiding,
                actor: $actor,
                before: [
                    'layaway' => $locked->code,
                    'amount_cents_usd' => $voiding->amount_cents_usd->cents,
                    'down_payment_cents' => $downPayment,
                    'unallocated_cents' => $voiding->unallocated_cents->cents,
                    'allocations' => $this->numbered($allocations, $installments),
                    'paid_cents' => $paidBefore,
                ] + ($receiptStatus !== null ? ['receipt_status' => ReceiptStatus::Approved->value] : []),
                after: [
                    'reason' => $motive->text,
                    'paid_cents' => $locked->paid_cents->cents,
                    'layaway_status' => $statusBefore->value.' → '.$locked->status->value,
                ] + ($receiptStatus !== null ? ['receipt_status' => $receiptStatus->value] : []),
            );

            ModelState::adopt($payment, $voiding->getAttributes());

            return $voiding;
        });
    }

    private function keepDownPaymentCovered(Layaway $layaway, int $downPayment): void
    {
        $closed = in_array($layaway->status, [LayawayStatus::Cancelled, LayawayStatus::Expired], true);

        if ($layaway->activated_at === null || $downPayment <= 0) {
            return;
        }

        $required = $layaway->terms()->totalDownPayment()->cents;
        $missing = $required - ($this->downPayments->paidOn($layaway) - $downPayment);

        if (! $closed && $missing > $this->balance->toleranceCents()) {
            throw PaymentNotVoidableException::coversDownPayment();
        }

        if ($missing > $layaway->down_payment_shortfall_cents->cents) {
            $layaway->forceFill(['down_payment_shortfall_cents' => new Money(min($required, $missing))])->save();
        }
    }

    /**
     * @param  array<int, Installment>  $installments
     * @param  array<int, int>  $allocations
     */
    private function resettle(array $installments, array $allocations): void
    {
        $now = CarbonImmutable::now();

        foreach ($installments as $id => $installment) {
            $paid = new Money($installment->paid_cents->cents - ($allocations[$id] ?? 0));
            $status = InstallmentStatus::resolve($installment->amount_cents, $paid, $this->overdue->isPastGrace($installment, $now));

            $installment->forceFill([
                'paid_cents' => $paid,
                'status' => $status,
                'paid_at' => $status === InstallmentStatus::Paid ? ($installment->paid_at ?? $now) : null,
            ]);

            if ($installment->isDirty()) {
                $installment->save();
            }
        }
    }

    private function returnReceiptToReview(Payment $payment): ?ReceiptStatus
    {
        if ($payment->receipt_id === null) {
            return null;
        }

        $receipt = Receipt::query()->whereKey($payment->receipt_id)->lockForUpdate()->first();

        if (! $receipt instanceof Receipt || $receipt->status !== ReceiptStatus::Approved) {
            return null;
        }

        $receipt->forceFill([
            'status' => ReceiptStatus::PendingReview,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason_code' => null,
            'rejection_reason' => null,
        ])->save();

        return $receipt->status;
    }

    /**
     * @param  array<int, Installment>  $installments
     */
    private function reopenIfOwing(Layaway $layaway, LayawayStatus $statusBefore, array $installments): void
    {
        if ($statusBefore !== LayawayStatus::Completed || $layaway->remaining_cents->cents <= $this->balance->toleranceCents()) {
            return;
        }

        $overdue = array_filter($installments, fn (Installment $installment): bool => $installment->status === InstallmentStatus::Overdue);

        $this->machine->reopen($layaway, $overdue === [] ? LayawayStatus::Active : LayawayStatus::Overdue);
    }

    /**
     * @param  array<int, int>  $centsById
     * @param  array<int, Installment>  $installments
     * @return list<array{installment: int, cents: int}>
     */
    private function numbered(array $centsById, array $installments): array
    {
        $rows = [];

        foreach ($centsById as $id => $cents) {
            $rows[] = ['installment' => $installments[$id]->number, 'cents' => $cents];
        }

        usort($rows, fn (array $a, array $b): int => $a['installment'] <=> $b['installment']);

        return $rows;
    }
}
