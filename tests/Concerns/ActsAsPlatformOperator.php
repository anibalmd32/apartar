<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Models\User;

trait ActsAsPlatformOperator
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function operator(array $attributes = []): PlatformUser
    {
        return PlatformUser::factory()->create($attributes);
    }

    protected function asOperator(?PlatformUser $operator = null): PlatformUser
    {
        $operator ??= $this->operator();
        $this->actingAs($operator, 'platform');

        return $operator;
    }

    /**
     * @return array{Organization, User}
     */
    protected function businessWithOwner(?Plan $plan = null): array
    {
        $organization = Organization::factory()->create();

        $owner = $this->withinOrganization($organization, function () use ($plan): User {
            Subscription::factory()->forPlan($plan ?? Plan::factory()->create())->create();

            return User::factory()->owner()->create();
        });

        $this->withoutOrganization();

        return [$organization, $owner];
    }
}
