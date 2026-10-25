<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use App\Models\User;

final class VisitsEveryOrganizationJob
{
    use RunsForEveryOrganization;

    /** @var array<int, int> */
    public array $usersPerOrganization = [];

    public function handle(): void
    {
        $this->forEachOrganization(function (Organization $organization): void {
            $this->usersPerOrganization[(int) $organization->getKey()] = User::query()->count();
        });
    }
}
