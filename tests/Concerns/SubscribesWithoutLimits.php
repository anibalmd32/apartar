<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;

trait SubscribesWithoutLimits
{
    protected function subscribeWithoutLimits(?Organization $organization = null): void
    {
        $context = app(TenantContext::class);
        $organization ??= $context->organization();

        $context->runFor($organization, function (): void {
            if (Subscription::query()->exists()) {
                return;
            }

            Subscription::factory()->forPlan(Plan::factory()->unlimited()->hidden()->withEveryFeature()->create())->create();
        });
    }
}
