<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Catalog\WithdrawCatalogsAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationHasActiveLayawaysException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use App\Support\ModelState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CloseOrganizationAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly WithdrawCatalogsAction $catalogs,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws OrganizationHasActiveLayawaysException
     */
    public function handle(User $owner): Organization
    {
        $organization = $this->context->organization();

        Gate::forUser($owner)->authorize('close', $organization);

        return DB::transaction(function () use ($owner, $organization): Organization {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->lockForUpdate()->firstOrFail();

            /** @var Organization $locked */
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === OrganizationStatus::Closed) {
                return $locked;
            }

            $open = Layaway::query()
                ->whereIn('status', array_values(array_filter(LayawayStatus::cases(), fn (LayawayStatus $status): bool => ! $status->isTerminal())))
                ->orderBy('id')
                ->sharedLock()
                ->pluck('code')
                ->map(fn (mixed $code): string => (string) $code)
                ->values()
                ->all();

            if ($open !== []) {
                throw new OrganizationHasActiveLayawaysException($open);
            }

            $before = ['organization' => $locked->status->value, 'subscription' => $subscription->status->value];

            $locked->forceFill(['status' => OrganizationStatus::Closed])->save();
            $subscription->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'grace_ends_at' => null,
                'scheduled_plan_id' => null,
                'scheduled_plan_at' => null,
            ])->save();

            $withdrawn = $this->catalogs->handle();

            $this->audit(
                AuditEvent::OrganizationClosed,
                subject: $locked,
                actor: $owner,
                before: $before,
                after: [
                    'organization' => OrganizationStatus::Closed->value,
                    'subscription' => SubscriptionStatus::Cancelled->value,
                    'unpublished_catalogs' => $withdrawn,
                ],
            );

            ModelState::adopt($organization, $locked->getAttributes());

            PlatformAudit::record(PlatformAudit::ORGANIZATION_CLOSED, 'El negocio cerró su cuenta.', null, (int) $organization->getKey(), ['closed_by_user_id' => $owner->getKey()]);

            return $organization;
        });
    }
}
