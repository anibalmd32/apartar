<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Subscription\IssueInvoiceAction;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Queries\Platform\RenewableSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IssueRenewalInvoices implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(RenewableSubscriptions $renewable, TenantContext $context, IssueInvoiceAction $invoices): int
    {
        $now = CarbonImmutable::now();
        $issued = 0;

        foreach ($renewable->organizationIds($now) as $organizationId) {
            $organization = Organization::query()->find($organizationId);

            if (! $organization instanceof Organization) {
                continue;
            }

            try {
                $issued += $context->runFor($organization, fn (): int => $this->renew($organization, $invoices));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Log::info('subscriptions.renewal_invoices_issued', ['issued' => $issued]);

        return $issued;
    }

    private function renew(Organization $organization, IssueInvoiceAction $invoices): int
    {
        return DB::transaction(function () use ($organization, $invoices): int {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->with(['plan', 'scheduledPlan'])->lockForUpdate()->firstOrFail();
            $end = $subscription->current_period_end?->toImmutable();

            if ($end === null || $subscription->status === SubscriptionStatus::Cancelled || ! self::isDue($end, $organization->timezone)) {
                return 0;
            }

            $plan = $this->planToBill($subscription, $end);

            if ($plan->price_cents <= 0) {
                return 0;
            }

            $already = SubscriptionInvoice::query()
                ->where('period_start', $end)
                ->where('status', '!=', InvoiceStatus::Void)
                ->exists();

            if ($already) {
                return 0;
            }

            try {
                $invoices->handle(
                    $subscription,
                    $plan,
                    InvoiceKind::Renewal,
                    $end,
                    $plan->periodEndFrom($end, self::anchorDayOf($subscription)),
                    $end->setTimezone($organization->timezone)->startOfDay(),
                );
            } catch (OrganizationDocumentRequiredException) {
                Log::warning('subscriptions.renewal_without_document', ['organization_id' => $organization->getKey()]);

                return 0;
            }

            return 1;
        });
    }

    private static function isDue(CarbonImmutable $end, string $timezone): bool
    {
        $limit = CarbonImmutable::now($timezone)->addDays(RenewableSubscriptions::DAYS_AHEAD)->toDateString();

        return $end->setTimezone($timezone)->toDateString() <= $limit;
    }

    private static function anchorDayOf(Subscription $subscription): int
    {
        return max($subscription->current_period_start->day, (int) $subscription->current_period_end?->day);
    }

    private function planToBill(Subscription $subscription, CarbonImmutable $end): Plan
    {
        $scheduled = $subscription->scheduledPlan;

        if ($scheduled instanceof Plan && $subscription->scheduled_plan_at !== null && $subscription->scheduled_plan_at->lessThanOrEqualTo($end)) {
            return $scheduled;
        }

        return $subscription->plan;
    }
}
