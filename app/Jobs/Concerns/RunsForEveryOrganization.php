<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Closure;

trait RunsForEveryOrganization
{
    /**
     * @param  Closure(Organization): void  $callback
     */
    protected function forEachOrganization(Closure $callback): void
    {
        $context = app(TenantContext::class);

        foreach ($this->organizationsToVisit() as $organization) {
            $context->runFor($organization, static function () use ($callback, $organization): void {
                $callback($organization);
            });
        }
    }

    /**
     * @return iterable<int, Organization>
     */
    protected function organizationsToVisit(): iterable
    {
        return Organization::query()->orderBy('id')->cursor();
    }
}
