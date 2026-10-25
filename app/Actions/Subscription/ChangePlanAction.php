<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Subscription\PlanChangeDirection;
use App\Domain\Subscription\PlanChangePreview;
use App\Domain\Subscription\PlanDowngradeNeedsConfirmationException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class ChangePlanAction
{
    use LogsSensitiveActivity;

    public const SUPERSEDED_REASON = 'Sustituida por un cambio de plan.';

    public function __construct(
        private readonly TenantContext $context,
        private readonly IssueInvoiceAction $invoices,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws OrganizationDocumentRequiredException
     * @throws PlanDowngradeNeedsConfirmationException
     */
    public function handle(Organization $organization, Plan $target, User $actor, bool $confirmed = false): Subscription
    {
        return $this->context->runFor($organization, fn (): Subscription => DB::transaction(function () use ($target, $actor, $confirmed): Subscription {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->with(['plan', 'scheduledPlan'])->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('changePlan', $subscription);

            $direction = PlanChangeDirection::between($subscription->plan, $target);

            if ($direction !== PlanChangeDirection::Same && ! $target->is_public) {
                throw new InvalidArgumentException(sprintf('El plan %s no está a la venta.', $target->code));
            }

            $before = $this->snapshot($subscription);

            $changed = match ($direction) {
                PlanChangeDirection::Upgrade => $this->upgrade($subscription, $target, $actor),
                PlanChangeDirection::Downgrade => $this->scheduleDowngrade($subscription, $target, $confirmed),
                PlanChangeDirection::Same => $this->revert($subscription),
            };

            if ($changed) {
                $subscription->load(['plan', 'scheduledPlan']);

                $this->audit(
                    AuditEvent::SubscriptionPlanChanged,
                    subject: $subscription,
                    actor: $actor,
                    before: $before,
                    after: $this->snapshot($subscription),
                );
            }

            return $subscription;
        }));
    }

    private function upgrade(Subscription $subscription, Plan $target, User $actor): bool
    {
        if ($target->price_cents > 0) {
            IssueInvoiceAction::assertCanBeInvoiced($this->context->organization()->document);
        }

        $now = CarbonImmutable::now();

        $subscription->forceFill([
            'plan_id' => $target->getKey(),
            'status' => $subscription->status === SubscriptionStatus::Grace ? SubscriptionStatus::Active : $subscription->status,
            'scheduled_plan_id' => null,
            'scheduled_plan_at' => null,
            'cancelled_at' => null,
        ]);

        if (! $subscription->status->isDelinquent()) {
            $subscription->forceFill([
                'current_period_start' => $now,
                'current_period_end' => $target->periodEndFrom($now),
            ]);
        }

        $subscription->save();

        $this->voidSuperseded($subscription, includingPlanChanges: true);

        if ($target->price_cents > 0) {
            $today = CarbonImmutable::now($this->context->organization()->timezone)->startOfDay();

            $this->invoices->handle(
                $subscription,
                $target,
                InvoiceKind::PlanChange,
                $now,
                $target->periodEndFrom($now),
                $today,
                $actor,
            );
        }

        return true;
    }

    private function scheduleDowngrade(Subscription $subscription, Plan $target, bool $confirmed): bool
    {
        if ($subscription->scheduled_plan_id === $target->getKey()) {
            return false;
        }

        $preview = PlanChangePreview::forDowngrade($subscription, $target);

        if ($preview->hasExcess() && ! $confirmed) {
            throw new PlanDowngradeNeedsConfirmationException($preview);
        }

        $subscription->forceFill([
            'status' => $subscription->status === SubscriptionStatus::Active ? SubscriptionStatus::Grace : $subscription->status,
            'scheduled_plan_id' => $target->getKey(),
            'scheduled_plan_at' => $preview->effectiveAt,
            'cancelled_at' => $target->is(StartFreeSubscriptionAction::freePlan())
                ? ($subscription->cancelled_at ?? CarbonImmutable::now())
                : null,
        ])->save();

        $this->voidSuperseded($subscription, includingPlanChanges: false);

        return true;
    }

    private function revert(Subscription $subscription): bool
    {
        if (! $subscription->hasScheduledChange()) {
            return false;
        }

        $subscription->forceFill([
            'status' => $subscription->status === SubscriptionStatus::Grace ? SubscriptionStatus::Active : $subscription->status,
            'scheduled_plan_id' => null,
            'scheduled_plan_at' => null,
            'cancelled_at' => null,
        ])->save();

        $this->voidSuperseded($subscription, includingPlanChanges: false);

        return true;
    }

    private function voidSuperseded(Subscription $subscription, bool $includingPlanChanges): void
    {
        if ($subscription->status->isDelinquent()) {
            return;
        }

        $now = CarbonImmutable::now();

        SubscriptionInvoice::query()
            ->where('status', InvoiceStatus::Pending)
            ->where('paid_cents', 0)
            ->where('due_date', '>=', CarbonImmutable::now($this->context->organization()->timezone)->toDateString())
            ->where(fn ($query) => $query
                ->where(fn ($renewals) => $renewals->where('kind', InvoiceKind::Renewal)->where('period_start', '>=', $now))
                ->when($includingPlanChanges, fn ($or) => $or->orWhere('kind', InvoiceKind::PlanChange)))
            ->lockForUpdate()
            ->get()
            ->each(fn (SubscriptionInvoice $invoice) => $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => $now,
                'void_reason' => self::SUPERSEDED_REASON,
            ])->save());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Subscription $subscription): array
    {
        return [
            'plan' => $subscription->plan->code,
            'status' => $subscription->status->value,
            'scheduled_plan' => $subscription->scheduledPlan?->code,
            'scheduled_plan_at' => $subscription->scheduled_plan_at?->toIso8601String(),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'current_period_start' => $subscription->current_period_start->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'downgrade_applied_at' => $subscription->downgrade_applied_at?->toIso8601String(),
        ];
    }
}
