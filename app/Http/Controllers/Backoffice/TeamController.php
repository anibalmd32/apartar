<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Organization\ChangeTeamMemberRoleAction;
use App\Actions\Organization\DisableTeamMemberAction;
use App\Actions\Organization\EnableTeamMemberAction;
use App\Actions\Organization\InviteTeamMemberAction;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\LastOwnerGuard;
use App\Domain\Tenancy\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ChangeTeamMemberRoleRequest;
use App\Http\Requests\Backoffice\InviteTeamMemberRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController extends Controller
{
    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly LastOwnerGuard $guard,
    ) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        return Inertia::render('backoffice/team/Index', [
            'members' => $this->members($request),
            'usage' => $this->limits->usageOf(UsageMetric::Users)->toArray(),
        ]);
    }

    public function store(InviteTeamMemberRequest $request, InviteTeamMemberAction $invite): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $member = $invite->handle(
            (string) $request->string('name'),
            (string) $request->string('email'),
            $request->role(),
            $actor,
        );

        return to_route('team.index')->with('status', sprintf(
            'Invitación enviada a %s. El enlace vale 48 horas.',
            $member->email,
        ));
    }

    public function resend(Request $request, User $member, InviteTeamMemberAction $invite): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $invite->resend($member, $actor);

        return to_route('team.index')->with('status', sprintf(
            'Enlace reenviado a %s. El anterior dejó de funcionar.',
            $member->email,
        ));
    }

    public function destroy(Request $request, User $member, InviteTeamMemberAction $invite): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $email = $member->email;

        $invite->revoke($member, $actor);

        return to_route('team.index')->with('status', sprintf('Invitación de %s revocada.', $email));
    }

    public function disable(Request $request, User $member, DisableTeamMemberAction $disable): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $disable->handle($member, $actor);

        return to_route('team.index')->with('status', sprintf(
            '%s ya no tiene acceso. Su historial se conserva.',
            $member->name,
        ));
    }

    public function role(
        ChangeTeamMemberRoleRequest $request,
        User $member,
        ChangeTeamMemberRoleAction $change,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $change->handle($member, $request->role(), $actor);

        return to_route('team.index')->with('status', sprintf(
            '%s ahora es %s.',
            $member->name,
            $member->role === OrganizationRole::Owner ? 'dueño' : 'empleado',
        ));
    }

    public function enable(Request $request, User $member, EnableTeamMemberAction $enable): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $enable->handle($member, $actor);

        return to_route('team.index')->with('status', sprintf(
            '%s vuelve a tener acceso y tendrá que elegir una contraseña nueva.',
            $member->name,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(Request $request): array
    {
        $actor = $request->user();

        return User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $member): array => [
                'id' => $member->getKey(),
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role->value,
                'isActive' => $member->disabled_at === null,
                'invitedAt' => $member->invited_at?->toIso8601String(),
                'lastLoginAt' => $member->last_login_at?->toIso8601String(),
                'isPending' => $member->hasPendingInvitation(),
                'isSelf' => $actor instanceof User && $member->is($actor),
                'isLastOwner' => $this->couldBeTheLastOwner($member) && $this->guard->isLastActiveOwner($member),
            ])
            ->values()
            ->all();
    }

    private function couldBeTheLastOwner(User $member): bool
    {
        return $member->disabled_at === null && $member->role === OrganizationRole::Owner;
    }
}
