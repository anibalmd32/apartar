<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Factories\Factory
 */
trait CreatesWithinTenant
{
    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $results
     */
    protected function store(Collection $results): void
    {
        $organization = $this->resolveTenantOrganization($results);

        app(TenantContext::class)->runFor($organization, function () use ($results): void {
            parent::store($results);
        });
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $results
     */
    private function resolveTenantOrganization(Collection $results): Organization
    {
        $context = app(TenantContext::class);

        if ($context->hasOrganization()) {
            return $context->organization();
        }

        $declared = $results
            ->map(fn ($model): mixed => $model->getAttribute('organization_id'))
            ->first(fn ($organizationId): bool => $organizationId !== null);

        if (is_numeric($declared)) {
            return Organization::query()->findOrFail((int) $declared);
        }

        return Organization::factory()->create();
    }
}
