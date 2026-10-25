<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Catalog\WithdrawCatalogsAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\InvoiceNotices;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SuspendOrganizationAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly WithdrawCatalogsAction $catalogs,
        private readonly InvoiceNotices $notices,
    ) {}

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

            if ($locked->status === OrganizationStatus::Suspended && $subscription->status === SubscriptionStatus::Suspended) {
                return false;
            }

            $before = ['organization' => $locked->status->value, 'subscription' => $subscription->status->value];

            $subscription->forceFill(['status' => SubscriptionStatus::Suspended])->save();
            $locked->forceFill([
                'status' => OrganizationStatus::Suspended,
                'suspended_at' => $locked->suspended_at ?? CarbonImmutable::now(),
            ])->save();

            $withdrawn = $this->catalogs->handle();

            $this->audit(
                AuditEvent::SubscriptionSuspended,
                subject: $subscription,
                actor: $actor,
                before: $before,
                after: [
                    'organization' => OrganizationStatus::Suspended->value,
                    'subscription' => SubscriptionStatus::Suspended->value,
                    'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
                    'unpublished_catalogs' => $withdrawn,
                ],
                process: $actor === null ? ($process ?? 'subscriptions:process-dunning') : null,
            );

            DB::afterCommit(function () use ($locked): void {
                try {
                    $this->notices->suspended($locked);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            ModelState::adopt($organization, $locked->getAttributes());

            return true;
        }));

        if ($changed) {
            PlatformAudit::record(PlatformAudit::ORGANIZATION_SUSPENDED, 'Organización suspendida.', $actor instanceof PlatformUser ? $actor : null, (int) $organization->getKey(), ['process' => $process]);
        }

        return $changed;
    }
}
