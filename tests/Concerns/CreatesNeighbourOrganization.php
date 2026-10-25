<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Closure;

trait CreatesNeighbourOrganization
{
    private ?Organization $neighbour = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function neighbourOrganization(array $attributes = []): Organization
    {
        return $this->neighbour ??= Organization::factory()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createNeighbourOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    protected function hasNeighbourOrganization(): bool
    {
        return Organization::query()->count() > 1;
    }

    protected function actingAsOrganization(Organization $organization): static
    {
        $context = app(TenantContext::class);

        $context->forget();
        $context->setOrganization($organization);

        return $this;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withinOrganization(Organization $organization, Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($organization, $callback);
    }

    protected function withoutOrganization(): static
    {
        app(TenantContext::class)->forget();

        return $this;
    }
}
