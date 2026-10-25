<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Organization\ReactivateOrganizationAction;
use App\Actions\Organization\SuspendOrganizationAction;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Platform\PlatformSettingKey;
use App\Domain\Subscription\DunningAssessment;
use App\Domain\Subscription\DunningVerdict;
use App\Domain\Subscription\InvoiceNotices;
use App\Domain\Subscription\InvoiceNoticeType;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Queries\OverdueSubscriptionInvoices;
use App\Queries\Platform\DunningCandidates;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSubscriptionDunning implements ShouldQueue
{
    use Queueable;

    public const PROCESS = 'subscriptions:process-dunning';

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @return array<string, int>
     */
    public function handle(
        DunningCandidates $candidates,
        TenantContext $context,
        OverdueSubscriptionInvoices $overdue,
        SuspendOrganizationAction $suspend,
        ReactivateOrganizationAction $reactivate,
        InvoiceNotices $notices,
    ): array {
        $now = CarbonImmutable::now();
        $tally = ['past_due' => 0, 'suspended' => 0, 'settled' => 0, 'notices' => 0];

        foreach ($candidates->organizationIds() as $organizationId) {
            $organization = Organization::query()->find($organizationId);

            if (! $organization instanceof Organization) {
                continue;
            }

            try {
                $context->runFor($organization, function () use ($organization, $now, $overdue, $suspend, $reactivate, $notices, &$tally): void {
                    $outcome = DB::transaction(fn (): ?array => $this->assess($organization, $now, $overdue, $suspend, $reactivate));

                    if ($outcome === null) {
                        return;
                    }

                    [$assessment, $changedTo, $billable, $debts] = $outcome;

                    if ($changedTo !== null) {
                        $tally[$changedTo]++;
                    }

                    if ($billable) {
                        $tally['notices'] += $this->notify($assessment, $debts, $notices);
                    }
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Log::info('subscriptions.dunning_processed', $tally);

        return $tally;
    }

    /**
     * @return array{0: DunningAssessment, 1: 'past_due'|'suspended'|'settled'|null, 2: bool, 3: Collection<int, SubscriptionInvoice>}|null
     */
    private function assess(
        Organization $organization,
        CarbonImmutable $now,
        OverdueSubscriptionInvoices $overdue,
        SuspendOrganizationAction $suspend,
        ReactivateOrganizationAction $reactivate,
    ): ?array {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->with('plan')->lockForUpdate()->firstOrFail();

        /** @var Organization $locked */
        $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status === OrganizationStatus::Closed) {
            return null;
        }

        $timezone = $locked->timezone;
        $billable = $subscription->plan->price_cents > 0;
        $debts = $overdue->lockedAt($now, $timezone);
        $receipts = $this->receiptsOf($debts);

        $assessment = DunningAssessment::of(
            status: $subscription->status,
            billable: $billable,
            debtSince: $this->debtSince($debts, $timezone),
            underReview: $receipts->contains(fn (SubscriptionReceipt $receipt): bool => $receipt->status === ReceiptStatus::PendingReview),
            lastReviewedAt: $this->lastReviewOf($receipts),
            now: $now,
            timezone: $timezone,
            graceDays: PlatformSettings::get(PlatformSettingKey::SubscriptionGraceDays),
        );

        $changedTo = match ($assessment->verdict) {
            DunningVerdict::Untouched => null,
            DunningVerdict::Settled => $this->settle($subscription, $locked, $reactivate),
            DunningVerdict::PastDue => $this->keepPastDue($subscription, $assessment),
            DunningVerdict::Suspended => $this->suspend($subscription, $locked, $assessment, $suspend),
        };

        return [$assessment, $changedTo, $billable, $debts];
    }

    /**
     * @return 'settled'|null
     */
    private function settle(Subscription $subscription, Organization $organization, ReactivateOrganizationAction $reactivate): ?string
    {
        if ($subscription->status === SubscriptionStatus::Suspended || $organization->status === OrganizationStatus::Suspended) {
            $reactivate->handle($organization, null, self::PROCESS);

            return 'settled';
        }

        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return null;
        }

        $subscription->forceFill([
            'status' => $subscription->hasScheduledChange() ? SubscriptionStatus::Grace : SubscriptionStatus::Active,
            'grace_ends_at' => null,
        ])->save();

        return 'settled';
    }

    /**
     * @return 'past_due'|null
     */
    private function keepPastDue(Subscription $subscription, DunningAssessment $assessment): ?string
    {
        $entering = $subscription->status !== SubscriptionStatus::PastDue;

        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => self::storable($assessment->graceEndsAt),
        ]);

        if ($subscription->isDirty()) {
            $subscription->save();
        }

        return $entering ? 'past_due' : null;
    }

    /**
     * @return 'suspended'|null
     */
    private function suspend(Subscription $subscription, Organization $organization, DunningAssessment $assessment, SuspendOrganizationAction $suspend): ?string
    {
        if ($subscription->grace_ends_at === null) {
            $subscription->forceFill(['grace_ends_at' => self::storable($assessment->graceEndsAt)])->save();
        }

        return $suspend->handle($organization, null, self::PROCESS) ? 'suspended' : null;
    }

    private static function storable(?CarbonImmutable $instant): ?CarbonImmutable
    {
        return $instant?->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  Collection<int, SubscriptionInvoice>  $debts
     */
    private function notify(DunningAssessment $assessment, Collection $debts, InvoiceNotices $notices): int
    {
        if ($assessment->verdict === DunningVerdict::Untouched) {
            return 0;
        }

        $sent = 0;

        foreach ($this->notYetDue($debts) as $invoice) {
            $sent += (int) $notices->sendOnce($invoice, InvoiceNoticeType::Issued);
        }

        if ($assessment->graceLastDay === null) {
            return $sent;
        }

        foreach ($debts as $invoice) {
            $sent += (int) $notices->sendOnce($invoice, InvoiceNoticeType::Overdue, $assessment->graceLastDay);
        }

        $reminder = $assessment->verdict === DunningVerdict::PastDue && $assessment->daysLeft !== null
            ? InvoiceNoticeType::graceReminderFor($assessment->daysLeft)
            : null;

        $oldest = $debts->first();

        if ($reminder !== null && $oldest instanceof SubscriptionInvoice) {
            $sent += (int) $notices->sendOnce($oldest, $reminder, $assessment->graceLastDay);
        }

        return $sent;
    }

    /**
     * @param  Collection<int, SubscriptionInvoice>  $debts
     * @return Collection<int, SubscriptionInvoice>
     */
    private function notYetDue(Collection $debts): Collection
    {
        return SubscriptionInvoice::query()
            ->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::UnderReview])
            ->whereKeyNot($debts->modelKeys())
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, SubscriptionInvoice>  $debts
     */
    private function debtSince(Collection $debts, string $timezone): ?CarbonImmutable
    {
        return $debts
            ->map(fn (SubscriptionInvoice $invoice): CarbonImmutable => DunningAssessment::debtStartOf($invoice->due_date->toImmutable(), $invoice->issued_at->toImmutable(), $timezone))
            ->sort()
            ->first();
    }

    /**
     * @param  Collection<int, SubscriptionInvoice>  $debts
     * @return Collection<int, SubscriptionReceipt>
     */
    private function receiptsOf(Collection $debts): Collection
    {
        if ($debts->isEmpty()) {
            return new Collection;
        }

        return SubscriptionReceipt::query()
            ->whereIn('subscription_invoice_id', $debts->modelKeys())
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, SubscriptionReceipt>  $receipts
     */
    private function lastReviewOf(Collection $receipts): ?CarbonImmutable
    {
        return $receipts
            ->map(fn (SubscriptionReceipt $receipt): ?CarbonImmutable => $receipt->reviewed_at?->toImmutable())
            ->filter()
            ->sort()
            ->last();
    }
}
