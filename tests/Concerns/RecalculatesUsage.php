<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Subscription\UsageCalculator;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;

trait RecalculatesUsage
{
    protected function recalculateUsage(?Organization $organization = null): void
    {
        $organization ??= app(TenantContext::class)->organization();

        app(UsageCalculator::class)->recalculate($organization);
    }
}
