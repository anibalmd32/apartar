<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\LastOwnerGuard;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChangeTeamMemberRoleAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly LastOwnerGuard $guard) {}

    /**
     * @throws AuthorizationException
     * @throws LastOwnerException
     */
    public function handle(User $member, OrganizationRole $role, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $member);

        if ($member->role === $role) {
            return $member;
        }

        $previous = $member->role;

        return DB::transaction(function () use ($member, $role, $actor, $previous): User {
            $member = match ($role) {
                OrganizationRole::Owner => $this->promote($member),
                OrganizationRole::Member => $this->demote($member),
            };

            $this->audit(
                AuditEvent::TeamMemberRoleChanged,
                subject: $member,
                actor: $actor,
                before: ['role' => $previous],
                after: ['role' => $member->role],
            );

            return $member;
        });
    }

    private function promote(User $member): User
    {
        $member->forceFill(['role' => OrganizationRole::Owner])->save();

        return $member;
    }

    /**
     * @throws LastOwnerException
     */
    private function demote(User $member): User
    {
        return $this->guard->whileKeepingAnOwner($member, 'degradar', function () use ($member): User {
            $member->forceFill(['role' => OrganizationRole::Member])->save();

            return $member;
        });
    }
}
