<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\StaleRate;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\Money;
use App\Domain\Notification\CustomerNotices;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ApproveReceiptAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly PaymentApplier $applier,
        private readonly ExchangeRateService $rates,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ReceiptAlreadyProcessedException
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     * @throws UnconfirmedExcessException
     * @throws UnconfirmedStaleRateException
     */
    public function handle(Receipt $receipt, ApproveReceiptData $data, User $actor): AppliedPayment
    {
        Gate::forUser($actor)->authorize('review', $receipt);

        return DB::transaction(function () use ($receipt, $data, $actor): AppliedPayment {
            /** @var Layaway $layaway */
            $layaway = Layaway::query()->whereKey($receipt->layaway_id)->lockForUpdate()->firstOrFail();

            /** @var Receipt $locked */
            $locked = Receipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->awaitsReview()) {
                throw ReceiptAlreadyProcessedException::of($locked);
            }

            $rate = $data->manualRate() ?? $this->rates->current($this->context->organization());
            StaleRate::in($rate, $data->settledCurrency)?->assertConfirmedBy($data->confirmedStaleRate);

            $applied = $this->applier->apply(
                $layaway,
                $data->settled(),
                $rate,
                $data->settledAmount,
                $data->settledCurrency,
                $locked,
                null,
                $data->note,
            );

            $excess = $applied->plan->unallocatedCents;

            if ($excess > 0 && $excess !== $data->confirmedExcessCents) {
                throw new UnconfirmedExcessException(new Money($excess));
            }

            $locked->forceFill([
                'status' => ReceiptStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit(
                AuditEvent::ReceiptApproved,
                subject: $locked,
                actor: $actor,
                before: ['status' => ReceiptStatus::PendingReview->value],
                after: $this->detailOf($applied, $data),
            );

            ModelState::adopt($receipt, $locked->getAttributes());

            $credited = $applied->payment->amount_cents_usd;
            DB::afterCommit(fn () => app(CustomerNotices::class)->paymentCredited($layaway->refresh(), $credited));

            return $applied;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(AppliedPayment $applied, ApproveReceiptData $data): array
    {
        $numbers = Installment::query()
            ->whereKey(array_column($applied->plan->allocations, 'installmentId'))
            ->pluck('number', 'id')
            ->all();

        return [
            'status' => ReceiptStatus::Approved->value,
            'payment_id' => $applied->payment->getKey(),
            'amount_cents_usd' => $applied->payment->amount_cents_usd->cents,
            'settled' => $data->settledAmount.' '.$data->settledCurrency->value,
            'rate_applied' => $applied->payment->rate_applied,
            'rate_source' => $applied->payment->rate_source->value,
            'rate_published_at' => $applied->payment->rate_published_at?->toIso8601String(),
            'down_payment_cents' => $applied->downPaymentCents,
            'allocations' => array_map(fn (array $allocation): array => [
                'installment' => $numbers[$allocation['installmentId']] ?? null,
                'cents' => $allocation['cents'],
            ], $applied->plan->allocations),
            'unallocated_cents' => $applied->plan->unallocatedCents,
            'layaway_status' => $applied->statusBefore->value.' → '.$applied->statusAfter->value,
        ] + ($data->manualRate() !== null ? ['rate_override' => $data->rateOverride] : []);
    }
}
