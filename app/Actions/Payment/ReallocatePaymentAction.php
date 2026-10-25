<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Money;
use App\Domain\Payment\AllocationPlan;
use App\Domain\Payment\InvalidReallocationException;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class ReallocatePaymentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly DetectOverdueAction $overdue,
        private readonly LayawayBalanceRecalculator $balance,
    ) {}

    /**
     * @param  list<array{installmentId: int, cents: int}>  $allocations
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws InvalidReallocationException
     */
    public function handle(Payment $payment, array $allocations, User $actor, string $reason): AllocationPlan
    {
        $layaway = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($actor)->authorize('manage', $layaway);
        $motive = new StaffReason($reason);

        return DB::transaction(function () use ($payment, $allocations, $actor, $motive): AllocationPlan {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($payment->layaway_id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [LayawayStatus::Cancelled, LayawayStatus::Expired], true)) {
                throw InvalidReallocationException::layawayClosed($locked->status);
            }

            /** @var array<int, Installment> $installments */
            $installments = $locked->installments()->lockForUpdate()->get()->keyBy('id')->all();

            /** @var array<int, int> $current */
            $current = PaymentAllocation::query()->where('payment_id', $payment->getKey())->lockForUpdate()->get()
                ->mapWithKeys(fn (PaymentAllocation $allocation): array => [(int) $allocation->installment_id => $allocation->amount_cents->cents])
                ->all();

            $plan = $this->validated($allocations, $current, $installments, $payment);
            $statusBefore = $locked->status;

            PaymentAllocation::query()->where('payment_id', $payment->getKey())->delete();

            foreach ($plan->allocations as $allocation) {
                (new PaymentAllocation)->forceFill([
                    'payment_id' => $payment->getKey(),
                    'installment_id' => $allocation['installmentId'],
                    'amount_cents' => new Money($allocation['cents']),
                ])->save();
            }

            $this->resettle($installments, $current, $plan);
            $this->balance->recalculate($locked);

            $this->audit(
                AuditEvent::PaymentReallocated,
                subject: $payment,
                actor: $actor,
                before: ['allocations' => $this->numbered($current, $installments)],
                after: [
                    'allocations' => $this->numbered(array_column($plan->allocations, 'cents', 'installmentId'), $installments),
                    'reason' => $motive->text,
                    'layaway_status' => $statusBefore->value.' → '.$locked->status->value,
                ],
            );

            return $plan;
        });
    }

    /**
     * @param  list<array{installmentId: int, cents: int}>  $allocations
     * @param  array<int, int>  $current
     * @param  array<int, Installment>  $installments
     */
    private function validated(array $allocations, array $current, array $installments, Payment $payment): AllocationPlan
    {
        if ($current === []) {
            throw InvalidReallocationException::nothingAllocated();
        }

        try {
            $plan = new AllocationPlan($allocations, $payment->unallocated_cents->cents);
        } catch (InvalidArgumentException $exception) {
            throw InvalidReallocationException::malformed($exception->getMessage());
        }

        foreach ($plan->allocations as $allocation) {
            if (! isset($installments[$allocation['installmentId']])) {
                throw InvalidReallocationException::foreignInstallment();
            }
        }

        if ($plan->allocatedCents() !== array_sum($current)) {
            throw InvalidReallocationException::sumMismatch(array_sum($current), $plan->allocatedCents());
        }

        foreach ($plan->allocations as $allocation) {
            $installment = $installments[$allocation['installmentId']];
            $room = $installment->amount_cents->cents - ($installment->paid_cents->cents - ($current[$allocation['installmentId']] ?? 0));

            if ($allocation['cents'] > $room) {
                throw InvalidReallocationException::overpaysInstallment($installment->number, max(0, $room));
            }
        }

        $proposed = array_column($plan->allocations, 'cents', 'installmentId');
        ksort($proposed);
        ksort($current);

        if ($proposed === $current) {
            throw InvalidReallocationException::unchanged();
        }

        return $plan;
    }

    /**
     * @param  array<int, Installment>  $installments
     * @param  array<int, int>  $current
     */
    private function resettle(array $installments, array $current, AllocationPlan $plan): void
    {
        $now = CarbonImmutable::now();

        foreach ($installments as $id => $installment) {
            $paid = new Money($installment->paid_cents->cents + $plan->centsFor($id) - ($current[$id] ?? 0));
            $status = InstallmentStatus::resolve($installment->amount_cents, $paid, $this->overdue->isPastGrace($installment, $now));

            $installment->forceFill([
                'paid_cents' => $paid,
                'status' => $status,
                'paid_at' => $status === InstallmentStatus::Paid ? ($installment->paid_at ?? $this->coveredAt($id)) : null,
            ]);

            if ($installment->isDirty()) {
                $installment->save();
            }
        }
    }

    private function coveredAt(int $installmentId): CarbonImmutable
    {
        $settled = Payment::query()
            ->whereIn('id', PaymentAllocation::query()->where('installment_id', $installmentId)->select('payment_id'))
            ->max('settled_at');

        return is_string($settled) ? CarbonImmutable::parse($settled) : CarbonImmutable::now();
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
