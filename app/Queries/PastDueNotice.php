<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class PastDueNotice
{
    public function __construct(private readonly OverdueSubscriptionInvoices $overdue) {}

    /**
     * @return array{amount: string|null, deadline: string|null, invoicesUrl: string|null}|null
     */
    public function of(Organization $organization, User $viewer): ?array
    {
        $subscription = Subscription::query()->first();

        if (! $subscription instanceof Subscription || $subscription->status !== SubscriptionStatus::PastDue) {
            return null;
        }

        $now = CarbonImmutable::now();
        $billing = Gate::forUser($viewer)->allows('viewInvoices', $subscription);
        $ends = $subscription->grace_ends_at?->toImmutable();

        return [
            'amount' => $billing ? MoneyFormatter::format(new Money($this->overdue->outstandingCents($now, $organization->timezone))) : null,
            'deadline' => $ends !== null && $ends->greaterThan($now)
                ? $ends->setTimezone($organization->timezone)->locale('es')->isoFormat('D [de] MMMM')
                : null,
            'invoicesUrl' => $billing ? route('subscription.invoices.index') : null,
        ];
    }
}
