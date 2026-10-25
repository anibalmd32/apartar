<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\User;

final class CountsUsersJob
{
    use RunsInTenantContext;

    public int $seen = 0;

    public function __construct(private readonly int $organizationId) {}

    public function tenantOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function handle(): void
    {
        $this->runInTenantContext(function (): void {
            $this->seen = User::query()->count();
        });
    }
}
