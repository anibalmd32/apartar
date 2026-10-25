<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class TeamMemberships
{
    /**
     * @return Collection<int, User>
     */
    public function of(User $user): Collection
    {
        return $this->usable($user->email)
            ->with('organization:id,name,slug,status')
            ->orderBy('organization_id')
            ->get();
    }

    public function in(User $user, int $organizationId): ?User
    {
        return $this->usable($user->email)->where('organization_id', $organizationId)->first();
    }

    public function preferredFor(string $email): ?User
    {
        $email = mb_strtolower(trim($email));

        return $this->usable($email)
            ->orderByRaw('last_login_at IS NULL')
            ->orderByDesc('last_login_at')
            ->orderBy('id')
            ->first()
            ?? User::withoutTenantScope()->where('email', $email)->orderBy('id')->first();
    }

    public function accepted(string $email, int $exceptOrganizationId): ?User
    {
        return $this->usable($email)->where('organization_id', '!=', $exceptOrganizationId)->orderBy('id')->first();
    }

    public function syncPassword(User $user): void
    {
        DB::table('users')
            ->where('email', $user->email)
            ->where('id', '!=', $user->getKey())
            ->whereNotNull('password')
            ->update(['password' => $user->getAuthPassword()]);
    }

    /**
     * @return Builder<User>
     */
    private function usable(string $email): Builder
    {
        return User::withoutTenantScope()
            ->where('email', $email)
            ->whereNotNull('password')
            ->whereNull('disabled_at')
            ->whereHas('organization', fn (Builder $organizations) => $organizations->where('status', '!=', OrganizationStatus::Closed->value));
    }
}
