<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Auth\TeamMemberships;
use App\Domain\Tenancy\OwnedOrganizationLimitException;
use App\Domain\Tenancy\SlugTakenException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FoundOrganizationAction
{
    public function __construct(
        private readonly RegisterOrganizationAction $register,
        private readonly TeamMemberships $memberships,
    ) {}

    /**
     * @throws OwnedOrganizationLimitException
     * @throws SlugTakenException
     * @throws InvalidArgumentException
     */
    public function handle(User $person, string $name, string $slug): Organization
    {
        return DB::transaction(function () use ($person, $name, $slug): Organization {
            $this->memberships->lockPerson($person->email);

            if (! $this->memberships->mayFound($person->email)) {
                throw OwnedOrganizationLimitException::reached((int) config('platform.max_owned_organizations'));
            }

            return $this->register->handle(new RegisterOrganizationData(
                organizationName: $name,
                organizationSlug: $slug,
                ownerName: $person->name,
                ownerEmail: $person->email,
                ownerPassword: (string) $person->getAuthPassword(),
            ));
        });
    }
}
