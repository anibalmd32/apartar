<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Jobs\Concerns\RunsInTenantContext;
use RuntimeException;

final class FailsWithinTenantJob
{
    use RunsInTenantContext;

    public function __construct(private readonly int $organizationId) {}

    public function tenantOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function handle(): void
    {
        $this->runInTenantContext(function (): never {
            throw new RuntimeException('fallo a mitad del job');
        });
    }
}
