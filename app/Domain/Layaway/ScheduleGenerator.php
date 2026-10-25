<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use Carbon\CarbonImmutable;
use DateTimeZone;

final class ScheduleGenerator
{
    public const MIN_INSTALLMENTS = 1;

    public const MAX_INSTALLMENTS = 36;

    public const MIN_FREQUENCY_DAYS = 1;

    public const MAX_FREQUENCY_DAYS = 90;

    public function generate(
        LayawayTerms $terms,
        CarbonImmutable $activatedAt,
        DateTimeZone $organizationTimezone,
    ): AmortizationSchedule {
        $amounts = $terms->financingPlan()?->installments() ?? [];
        $activationDay = $activatedAt->setTimezone($organizationTimezone)->startOfDay();

        $installments = [];

        foreach ($amounts as $index => $amount) {
            $number = $index + 1;

            $installments[] = new ScheduledInstallment(
                $number,
                $activationDay->addDays($number * $terms->frequencyDays)->startOfDay(),
                $amount,
            );
        }

        return new AmortizationSchedule($installments, $terms->unitPrice->currency);
    }
}
