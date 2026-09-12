<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use App\Models\User;

/**
 * Job programado que recorre todas las organizaciones.
 *
 * Es el segundo mecanismo de `REQ-INF-003` RB-1b, el de `ExpireLayawaysJob` y
 * los otros seis: enumera sin filtro y fija el tenant por elemento.
 */
final class VisitsEveryOrganizationJob
{
    use RunsForEveryOrganization;

    /** @var array<int, int> usuarios contados en cada organización visitada */
    public array $usersPerOrganization = [];

    public function handle(): void
    {
        $this->forEachOrganization(function (Organization $organization): void {
            $this->usersPerOrganization[(int) $organization->getKey()] = User::query()->count();
        });
    }
}
