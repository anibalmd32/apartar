<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateDeviation;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\StaleRate;
use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Layaway\ScheduleGenerator;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;

final class OpeningPaymentPreview
{
    public function __construct(
        private readonly PaymentApplier $applier,
        private readonly ScheduleGenerator $generator,
        private readonly ExchangeRateService $rates,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     */
    public function for(LayawayTerms $terms, Money $settled, RateSnapshot|ManualRate|null $rate): ApprovalPreview
    {
        [$dollars, $snapshot] = $this->applier->toDollars($settled, $rate);
        $required = $terms->totalDownPayment();
        $statusBefore = $terms->requiresDownPayment() ? LayawayStatus::PendingDownPayment : LayawayStatus::Active;

        if ($dollars->cents < $required->cents) {
            throw PaymentNotApplicableException::belowDownPayment($dollars, $required);
        }

        $excess = $dollars->cents - $required->cents;
        $schedule = $this->scheduleOf($terms);
        $plan = $excess > 0 && $schedule->installments !== []
            ? FifoAllocator::planFor($schedule, new Money($excess))
            : new AllocationPlan([], $excess);

        $amounts = [];

        foreach ($schedule->installments as $installment) {
            $amounts[$installment->number] = $installment->amount->cents;
        }

        return new ApprovalPreview(
            amount: $dollars,
            rate: $snapshot['rate'],
            rateSource: $snapshot['source'],
            downPaymentCents: $required->cents,
            allocations: array_map(fn (array $allocation): array => [
                'number' => $allocation['installmentId'],
                'cents' => $allocation['cents'],
                'covers' => $allocation['cents'] >= ($amounts[$allocation['installmentId']] ?? 0),
            ], $plan->allocations),
            unallocatedCents: $plan->unallocatedCents,
            statusBefore: $statusBefore,
            statusAfter: $dollars->cents >= $terms->total()->cents ? LayawayStatus::Completed : LayawayStatus::Active,
            staleRate: StaleRate::in($rate, $settled->currency),
            rateDeviation: RateDeviation::in($rate, $this->rates->current($this->context->organization()), $settled->currency),
        );
    }

    private function scheduleOf(LayawayTerms $terms): AmortizationSchedule
    {
        $timezone = new DateTimeZone($this->context->organization()->timezone);
        $generated = $this->generator->generate($terms, CarbonImmutable::now(), $timezone);

        return new AmortizationSchedule(
            array_map(fn (ScheduledInstallment $installment): ScheduledInstallment => new ScheduledInstallment(
                $installment->number,
                $installment->dueDate,
                $installment->amount,
                installmentId: $installment->number,
            ), $generated->installments),
            $generated->currency,
        );
    }
}
