<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Money\Currency;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;

final class SuspensionNoticeQuery
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * @return array{suspendedAt: string|null, graceEndsAt: string|null, invoice: array{number: string, planName: string, kind: string, periodStart: string, periodEnd: string, dueDate: string, daysOverdue: int, amount: string, amountVes: string|null}|null}|null
     */
    public function for(Organization $organization): ?array
    {
        if ($organization->status !== OrganizationStatus::Suspended) {
            return null;
        }

        $subscription = Subscription::query()->first();
        $invoice = SubscriptionInvoice::query()
            ->with('plan')
            ->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::UnderReview])
            ->oldest('due_date')
            ->oldest('id')
            ->first();
        $today = CarbonImmutable::now($organization->timezone)->startOfDay();

        return [
            'suspendedAt' => $organization->suspended_at?->setTimezone($organization->timezone)->toDateString(),
            'graceEndsAt' => $subscription?->grace_ends_at?->setTimezone($organization->timezone)->toDateString(),
            'invoice' => $invoice instanceof SubscriptionInvoice ? $this->invoice($invoice, $today) : null,
        ];
    }

    /**
     * @return array{number: string, planName: string, kind: string, periodStart: string, periodEnd: string, dueDate: string, daysOverdue: int, amount: string, amountVes: string|null}
     */
    private function invoice(SubscriptionInvoice $invoice, CarbonImmutable $today): array
    {
        $outstanding = $invoice->outstanding();
        $rate = $this->rates->current(null);
        $due = CarbonImmutable::parse($invoice->due_date->toDateString(), $today->timezone);

        return [
            'number' => $invoice->number(),
            'planName' => $invoice->plan->name,
            'kind' => $invoice->kind->label(),
            'periodStart' => $invoice->period_start->toIso8601String(),
            'periodEnd' => $invoice->period_end->toIso8601String(),
            'dueDate' => $invoice->due_date->toDateString(),
            'daysOverdue' => max(0, (int) $due->diffInDays($today)),
            'amount' => MoneyFormatter::format($outstanding),
            'amountVes' => $rate === null ? null : MoneyFormatter::format($outstanding->convertTo(Currency::VES, $rate)),
        ];
    }
}
