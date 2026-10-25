<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use App\Support\ModelState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ReactivateOrganizationAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Organization $organization, ?Model $actor = null, ?string $process = null): bool
    {
        $changed = $this->context->runFor($organization, fn (): bool => DB::transaction(function () use ($organization, $actor, $process): bool {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->lockForUpdate()->firstOrFail();

            /** @var Organization $locked */
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === OrganizationStatus::Closed || $subscription->status === SubscriptionStatus::Cancelled) {
                return false;
            }

            if ($locked->status !== OrganizationStatus::Suspended && $subscription->status !== SubscriptionStatus::Suspended) {
                return false;
            }

            $before = [
                'organization' => $locked->status->value,
                'subscription' => $subscription->status->value,
                'suspended_at' => $locked->suspended_at?->toIso8601String(),
            ];

            $subscription->forceFill([
                'status' => $subscription->hasScheduledChange() ? SubscriptionStatus::Grace : SubscriptionStatus::Active,
                'grace_ends_at' => null,
            ])->save();

            $locked->forceFill(['status' => OrganizationStatus::Active, 'suspended_at' => null])->save();

            $this->audit(
                AuditEvent::SubscriptionReactivated,
                subject: $subscription,
                actor: $actor,
                before: $before,
                after: ['organization' => OrganizationStatus::Active->value, 'subscription' => $subscription->status->value],
                process: $actor === null ? ($process ?? 'subscriptions:process-dunning') : null,
            );

            ModelState::adopt($organization, $locked->getAttributes());

            return true;
        }));

        if ($changed) {
            PlatformAudit::record(PlatformAudit::ORGANIZATION_REACTIVATED, 'Organización reactivada.', $actor instanceof PlatformUser ? $actor : null, (int) $organization->getKey(), ['process' => $process]);
        }

        return $changed;
    }
}
