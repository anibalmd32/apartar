<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CannotDisableSelfException;
use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\LastOwnerGuard;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DisableTeamMemberAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly LastOwnerGuard $guard,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CannotDisableSelfException
     * @throws LastOwnerException
     */
    public function handle(User $member, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $member);

        if ($member->is($actor)) {
            throw CannotDisableSelfException::make();
        }

        if ($member->disabled_at !== null) {
            return $member;
        }

        return DB::transaction(fn (): User => $this->disable($member, $actor));
    }

    private function disable(User $member, User $actor): User
    {
        $this->limits->lockCounter(UsageMetric::Users);

        return $this->guard->whileKeepingAnOwner($member, 'desactivar', function () use ($member, $actor): User {
            $locked = User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            ModelState::adopt($member, $locked->getAttributes());

            if ($member->disabled_at !== null) {
                return $member;
            }

            $member->forceFill(['disabled_at' => now()])->save();

            $this->limits->adjust(UsageMetric::Users, -1);

            $this->audit(
                AuditEvent::TeamMemberDisabled,
                subject: $member,
                actor: $actor,
                before: ['access' => 'activo', 'role' => $member->role],
                after: ['access' => 'desactivado', 'disabled_at' => $member->disabled_at],
            );

            return $member;
        });
    }
}
