<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\User;

/**
 * Job de una sola organización, el caso normal.
 *
 * Su `handle()` es una sola llamada a `runInTenantContext()`, que es la forma
 * que va a tener todo job del tenant: el identificador viaja en el payload y
 * el trait se ocupa de fijarlo y de limpiarlo.
 */
final class CountsUsersJob
{
    use RunsInTenantContext;

    /** Cuántos usuarios vio el job. */
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
