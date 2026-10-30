<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Layaway\ScheduleGenerator;
use App\Http\Resources\Concerns\ProjectsMoney;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LayawayPlanResource extends JsonResource
{
    use ProjectsMoney;

    public function __construct(
        LayawayTerms $terms,
        private readonly DateTimeZone $timezone,
        private readonly ?CarbonImmutable $startsAt = null,
    ) {
        parent::__construct($terms);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LayawayTerms $terms */
        $terms = $this->resource;
        $plan = $terms->financingPlan();

        $schedule = (new ScheduleGenerator)->generate($terms, $this->startsAt ?? CarbonImmutable::now(), $this->timezone);

        return [
            'quantity' => $terms->quantity,
            'total' => $this->money($terms->total()),
            'downPayment' => $this->money($terms->totalDownPayment()),
            'requiresDownPayment' => $terms->requiresDownPayment(),
            'financedAmount' => $this->money($terms->financedAmount()),
            'installmentsCount' => $terms->installmentsCount,
            'frequencyDays' => $terms->frequencyDays,
            'installmentAmount' => $plan === null ? null : $this->money($plan->installmentAmount()),
            'lastInstallmentAmount' => $plan !== null && $plan->hasUnevenLastInstallment() ? $this->money($plan->lastInstallmentAmount()) : null,
            'installments' => array_map(fn (ScheduledInstallment $installment): array => [
                'number' => $installment->number,
                'estimatedDueDate' => $installment->dueDate->setTimezone($this->timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                'amount' => $this->money($installment->amount),
            ], $schedule->installments),
        ];
    }
}
