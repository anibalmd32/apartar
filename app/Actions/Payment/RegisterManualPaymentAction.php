<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateDeviation;
use App\Domain\Exchange\StaleRate;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\Money;
use App\Domain\Notification\CustomerNotices;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RegisterManualPaymentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly PaymentApplier $applier,
        private readonly ExchangeRateService $rates,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CrossTenantRelationException
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     * @throws UnconfirmedExcessException
     * @throws IdempotencyKeyReusedException
     * @throws UnconfirmedStaleRateException
     * @throws UnconfirmedRateDeviationException
     */
    public function handle(Layaway $layaway, ManualPaymentData $data, User $actor): Payment
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        $account = $data->paymentAccount;

        if ($account instanceof PaymentAccount && (int) $account->organization_id !== (int) $layaway->organization_id) {
            throw CrossTenantRelationException::between(PaymentAccount::class, Layaway::class);
        }

        $existing = $this->alreadyRegistered($layaway, $data);

        if ($existing instanceof Payment) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): Payment => $this->register($layaway, $data, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->alreadyRegistered($layaway, $data) ?? throw $exception;
        }
    }

    private function register(Layaway $layaway, ManualPaymentData $data, User $actor): Payment
    {
        Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

        $existing = $this->alreadyRegistered($layaway, $data);

        if ($existing instanceof Payment) {
            return $existing;
        }

        $rate = $data->manualRate() ?? $this->rates->current($this->context->organization());
        StaleRate::in($rate, $data->currency)?->assertConfirmedBy($data->confirmedStaleRate);
        RateDeviation::in($rate, $this->rates->current($this->context->organization()), $data->currency)?->assertConfirmedBy($data->confirmedRateDeviation);

        $applied = $this->applier->apply(
            $layaway,
            $data->money(),
            $rate,
            $data->amount,
            $data->currency,
            null,
            $actor,
            $data->note,
            $data->idempotencyKey,
            $data->origin(),
        );

        $excess = $applied->plan->unallocatedCents;

        if ($excess > 0 && $excess !== $data->confirmedExcessCents) {
            throw new UnconfirmedExcessException(new Money($excess));
        }

        $this->audit(
            AuditEvent::PaymentRecorded,
            subject: $applied->payment,
            actor: $actor,
            after: $this->detailOf($applied, $data),
        );

        $credited = $applied->payment->amount_cents_usd;
        DB::afterCommit(fn () => app(CustomerNotices::class)->paymentCredited($layaway->refresh(), $credited));

        return $applied->payment;
    }

    /**
     * @throws IdempotencyKeyReusedException
     */
    private function alreadyRegistered(Layaway $layaway, ManualPaymentData $data): ?Payment
    {
        $payment = Payment::query()->where('idempotency_key', $data->idempotencyKey)->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        $same = (int) $payment->layaway_id === (int) $layaway->getKey()
            && $payment->declared_currency === $data->currency
            && bccomp((string) $payment->declared_amount, $data->amount, 2) === 0;

        return $same ? $payment : throw new IdempotencyKeyReusedException;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(AppliedPayment $applied, ManualPaymentData $data): array
    {
        $numbers = Installment::query()
            ->whereKey(array_column($applied->plan->allocations, 'installmentId'))
            ->pluck('number', 'id')
            ->all();

        return [
            'layaway' => $applied->payment->layaway_id,
            'amount_cents_usd' => $applied->payment->amount_cents_usd->cents,
            'paid' => $data->amount.' '.$data->currency->value,
            'payment_method' => $data->method->value,
            'payment_account' => $data->paymentAccount?->getKey(),
            'paid_on' => $data->paidAt->toDateString(),
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
            'note' => $data->note,
        ] + ($data->manualRate() !== null ? ['rate_override' => $data->rateOverride] : []);
    }
}
