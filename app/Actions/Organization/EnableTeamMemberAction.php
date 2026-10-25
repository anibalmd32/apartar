<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class EnableTeamMemberAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly LimitEnforcer $limits) {}

    /**
     * @throws AuthorizationException
     * @throws PlanLimitReachedException
     */
    public function handle(User $member, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $member);

        if ($member->disabled_at === null) {
            return $member;
        }

        return DB::transaction(function () use ($member, $actor): User {
            $this->limits->lockCounter(UsageMetric::Users);

            $locked = User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            ModelState::adopt($member, $locked->getAttributes());

            if ($member->disabled_at === null) {
                return $member;
            }

            $this->limits->assertCanCreate(UsageMetric::Users);

            $member->forceFill([
                'disabled_at' => null,
                'must_change_password' => true,
            ])->save();

            $this->limits->adjust(UsageMetric::Users, 1);

            $this->audit(
                AuditEvent::TeamMemberEnabled,
                subject: $member,
                actor: $actor,
                before: ['access' => 'desactivado'],
                after: ['access' => 'activo', 'role' => $member->role],
            );

            return $member;
        });
    }
}
