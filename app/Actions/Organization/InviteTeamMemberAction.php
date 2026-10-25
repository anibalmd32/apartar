<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\InvitationNotPendingException;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TeamInvitation;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class InviteTeamMemberAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws \App\Domain\Subscription\PlanLimitReachedException
     * @throws ValidationException
     */
    public function handle(string $name, string $email, OrganizationRole $role, User $actor): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        $email = mb_strtolower(trim($email));

        [$member, $invitation] = DB::transaction(function () use ($name, $email, $role, $actor): array {
            $this->limits->assertCanCreate(UsageMetric::Users);

            $invitation = TeamInvitation::issueFor($this->context->organization());

            $member = $this->createInvitedUser($name, $email, $role, $invitation);

            $this->limits->adjust(UsageMetric::Users, 1);

            $this->audit(
                AuditEvent::TeamMemberInvited,
                subject: $member,
                actor: $actor,
                after: ['email' => $member->email, 'role' => $member->role, 'status' => 'invitación pendiente'],
            );

            return [$member, $invitation];
        });

        $this->deliver($member, $invitation);

        return $member;
    }

    /**
     * @throws AuthorizationException
     * @throws InvitationNotPendingException
     */
    public function resend(User $member, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $member);

        $this->assertStillPending($member);

        $invitation = TeamInvitation::issueFor($this->context->organization());

        $member->forceFill([
            'invited_at' => now(),
            'invitation_token_hash' => $invitation->tokenHash,
            'invitation_expires_at' => $invitation->expiresAt,
        ])->save();

        $this->deliver($member, $invitation);

        return $member;
    }

    /**
     * @throws AuthorizationException
     * @throws InvitationNotPendingException
     */
    public function revoke(User $member, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $member);

        $this->assertStillPending($member);

        DB::transaction(function () use ($member, $actor): void {
            $this->limits->lockCounter(UsageMetric::Users);

            $locked = User::query()->whereKey($member->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                return;
            }

            $this->assertStillPending($locked);

            $locked->delete();

            if ($locked->disabled_at === null) {
                $this->limits->adjust(UsageMetric::Users, -1);
            }

            $this->audit(
                AuditEvent::TeamInvitationRevoked,
                subject: $member,
                actor: $actor,
                before: ['email' => $member->email, 'role' => $member->role, 'status' => 'invitación pendiente'],
                after: ['status' => 'revocada'],
            );
        });
    }

    /**
     * @throws ValidationException
     */
    private function createInvitedUser(string $name, string $email, OrganizationRole $role, TeamInvitation $invitation): User
    {
        try {
            return User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => null,
                'role' => $role,
                'invited_at' => now(),
                'invitation_token_hash' => $invitation->tokenHash,
                'invitation_expires_at' => $invitation->expiresAt,
                'must_change_password' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya es del equipo de tu negocio.',
            ]);
        }
    }

    private function deliver(User $member, TeamInvitation $invitation): void
    {
        $member->notify(new TeamInvitationNotification(
            $invitation,
            $this->context->organization()->name,
        ));
    }

    /**
     * @throws InvitationNotPendingException
     */
    private function assertStillPending(User $member): void
    {
        if (! $member->hasPendingInvitation()) {
            throw InvitationNotPendingException::for($member->name);
        }
    }
}
