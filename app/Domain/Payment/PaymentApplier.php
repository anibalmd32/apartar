<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\StaleRate;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\User;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class PaymentApplier
{
    private const UNIT_RATE = '1.00000000';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ActivateLayawayAction $activation,
        private readonly FifoAllocator $allocator,
        private readonly DetectOverdueAction $overdue,
        private readonly LayawayBalanceRecalculator $balance,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     * @throws InvalidArgumentException
     */
    public function apply(
        Layaway $layaway,
        Money $settled,
        RateSnapshot|ManualRate|null $rate,
        string $declaredAmount,
        Currency $declaredCurrency,
        ?Receipt $receipt,
        ?User $registeredBy,
        ?string $note,
        ?string $idempotencyKey = null,
        ?PaymentOrigin $origin = null,
    ): AppliedPayment {
        PaymentAmounts::assertPositive($declaredAmount, $declaredCurrency, 'declarado');

        if ($settled->cents <= 0) {
            throw new InvalidArgumentException(sprintf('Un pago conciliado es mayor que cero, y es de %d céntimos.', $settled->cents));
        }

        if ($receipt === null && $registeredBy === null) {
            throw new InvalidArgumentException('Un pago sin comprobante dice quién lo registró.');
        }

        return DB::transaction(function () use ($layaway, $settled, $rate, $declaredAmount, $declaredCurrency, $receipt, $registeredBy, $note, $idempotencyKey, $origin): AppliedPayment {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if ($receipt !== null && $receipt->layaway_id !== $locked->getKey()) {
                throw new LogicException(sprintf('El comprobante %d es del apartado %d, no del %d.', $receipt->getKey(), $receipt->layaway_id, $locked->getKey()));
            }

            $statusBefore = $locked->status;

            if (! $statusBefore->acceptsPayments()) {
                throw PaymentNotApplicableException::layawayNotCollecting($statusBefore);
            }

            [$dollars, $snapshot] = $this->toDollars($settled, $rate);
            $downPayment = 0;

            if ($statusBefore === LayawayStatus::PendingDownPayment) {
                $required = $locked->terms()->totalDownPayment();

                if ($dollars->cents < $required->cents) {
                    throw PaymentNotApplicableException::belowDownPayment($dollars, $required);
                }

                $this->activation->handle($locked);
                $downPayment = $required->cents;
            }

            $excess = $dollars->cents - $downPayment;

            $plan = $excess > 0
                ? $this->allocator->plan($locked, new Money($excess))
                : new AllocationPlan([], 0);

            $origin ??= $receipt instanceof Receipt ? PaymentOrigin::ofReceipt($receipt) : null;

            $payment = $this->insertPayment($locked, $dollars, $plan, $snapshot, $declaredAmount, $declaredCurrency, $receipt, $registeredBy, $note, $idempotencyKey, $origin);

            $this->allocate($locked, $payment, $plan);

            $this->balance->recalculate($locked);

            ModelState::adopt($layaway, $locked->getAttributes());

            return new AppliedPayment($payment, $plan, $statusBefore, $locked->status, $downPayment);
        });
    }

    /**
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     */
    public function preview(
        Layaway $layaway,
        Money $settled,
        RateSnapshot|ManualRate|null $rate,
        string $declaredAmount,
        Currency $declaredCurrency,
        ?Receipt $receipt,
        ?User $registeredBy = null,
        ?PaymentOrigin $origin = null,
    ): ApprovalPreview {
        DB::beginTransaction();

        try {
            $applied = $this->apply(clone $layaway, $settled, $rate, $declaredAmount, $declaredCurrency, $receipt, $registeredBy, null, null, $origin);

            /** @var array<int, Installment> $installments */
            $installments = Installment::query()->where('layaway_id', $layaway->getKey())->get()->keyBy('id')->all();

            return new ApprovalPreview(
                amount: $applied->payment->amount_cents_usd,
                rate: $applied->payment->rate_applied,
                rateSource: $applied->payment->rate_source,
                downPaymentCents: $applied->downPaymentCents,
                allocations: array_map(fn (array $allocation): array => [
                    'number' => $installments[$allocation['installmentId']]->number,
                    'cents' => $allocation['cents'],
                    'covers' => $installments[$allocation['installmentId']]->status === InstallmentStatus::Paid,
                ], $applied->plan->allocations),
                unallocatedCents: $applied->plan->unallocatedCents,
                statusBefore: $applied->statusBefore,
                statusAfter: $applied->statusAfter,
                staleRate: StaleRate::in($rate, $settled->currency),
            );
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array{Money, array{rate: string, source: RateSource, publishedAt: ?CarbonImmutable}}
     */
    private function toDollars(Money $settled, RateSnapshot|ManualRate|null $rate): array
    {
        if ($settled->currency === Currency::USD) {
            return [$settled, ['rate' => self::UNIT_RATE] + $this->sourceOf($rate)];
        }

        if ($rate === null) {
            throw MissingExchangeRateException::whileSettling($this->rates->sourceFor($this->context->organization()));
        }

        $applied = self::toRateScale($rate->rate);
        $snapshot = new RateSnapshot($applied, RateSource::Manual, CarbonImmutable::now(), CarbonImmutable::now());

        $dollars = $settled->convertTo(Currency::USD, $snapshot);

        if ($dollars->cents <= 0) {
            throw PaymentNotApplicableException::convertsToZero($settled, $applied);
        }

        return [$dollars, ['rate' => $applied] + $this->sourceOf($rate)];
    }

    private static function toRateScale(string $rate): string
    {
        Money::assertUsableRate($rate);

        return bcadd($rate, '0.000000005', ManualRate::SCALE);
    }

    /**
     * @return array{source: RateSource, publishedAt: ?CarbonImmutable}
     */
    private function sourceOf(RateSnapshot|ManualRate|null $rate): array
    {
        return match (true) {
            $rate instanceof RateSnapshot => ['source' => $rate->source, 'publishedAt' => $rate->publishedAt],
            $rate instanceof ManualRate => ['source' => RateSource::Manual, 'publishedAt' => null],
            default => ['source' => $this->rates->sourceFor($this->context->organization()), 'publishedAt' => null],
        };
    }

    /**
     * @param  array{rate: string, source: RateSource, publishedAt: ?CarbonImmutable}  $snapshot
     */
    private function insertPayment(
        Layaway $layaway,
        Money $dollars,
        AllocationPlan $plan,
        array $snapshot,
        string $declaredAmount,
        Currency $declaredCurrency,
        ?Receipt $receipt,
        ?User $registeredBy,
        ?string $note,
        ?string $idempotencyKey,
        ?PaymentOrigin $origin,
    ): Payment {
        $payment = new Payment;

        $payment->forceFill([
            'layaway_id' => $layaway->getKey(),
            'receipt_id' => $receipt?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'amount_cents_usd' => $dollars,
            'unallocated_cents' => new Money($plan->unallocatedCents),
            'declared_currency' => $declaredCurrency,
            'declared_amount' => $declaredAmount,
            'rate_applied' => $snapshot['rate'],
            'rate_source' => $snapshot['source'],
            'rate_published_at' => $snapshot['publishedAt'],
            'settled_at' => CarbonImmutable::now(),
            'registered_by' => $registeredBy?->getKey(),
            'note' => $note,
            ...($origin?->toColumns() ?? []),
        ])->save();

        return $payment;
    }

    private function allocate(Layaway $layaway, Payment $payment, AllocationPlan $plan): void
    {
        $now = CarbonImmutable::now();

        foreach ($plan->allocations as $allocation) {
            $row = new PaymentAllocation;
            $row->forceFill([
                'payment_id' => $payment->getKey(),
                'installment_id' => $allocation['installmentId'],
                'amount_cents' => new Money($allocation['cents']),
            ])->save();
        }

        foreach ($layaway->installments()->lockForUpdate()->get() as $installment) {
            $paid = new Money($installment->paid_cents->cents + $plan->centsFor((int) $installment->getKey()));
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
}
