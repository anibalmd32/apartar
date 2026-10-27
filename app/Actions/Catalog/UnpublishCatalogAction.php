<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UnpublishCatalogAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(Catalog $catalog, User $actor): Catalog
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        if ($catalog->visibility() === CatalogVisibility::Draft) {
            return $catalog;
        }

        return DB::transaction(function () use ($catalog, $actor): Catalog {
            $this->limits->lockCounter(UsageMetric::Catalogs);

            ModelState::adopt($catalog, Catalog::query()->whereKey($catalog->getKey())->lockForUpdate()->firstOrFail()->getAttributes());

            $this->usage->recountLocked(UsageMetric::Catalogs);

            if ($catalog->visibility() === CatalogVisibility::Draft) {
                return $catalog;
            }

            $counted = $catalog->visibility()->countsTowardPlanLimit();

            $before = [
                'visibility' => $catalog->visibility()->value,
                'published_at' => $catalog->published_at?->toIso8601String(),
            ];

            $catalog->editedBy($actor)->forceFill(['published_at' => null, 'withdrawn_at' => now()])->save();

            if ($counted) {
                $this->limits->adjust(UsageMetric::Catalogs, -1);
            }

            $this->audit(
                AuditEvent::CatalogUnpublished,
                subject: $catalog,
                actor: $actor,
                before: $before,
                after: ['visibility' => $catalog->visibility()->value, 'published_at' => null],
            );

            return $catalog;
        });
    }
}
