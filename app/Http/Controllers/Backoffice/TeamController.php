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

/**
 * La pantalla desde la que un dueño delega (`REQ-ORG-004`, `REQ-ORG-005`).
 *
 * Fino a propósito: cada operación es una línea a su acción, que es quien
 * puede garantizar la transacción, el bloqueo y el ajuste del cupo. Lo que sí
 * decide este archivo es **qué se ve**, y ahí hay dos cosas que no son
 * decoración:
 *
 *  - **el consumo del plan viaja con el listado** (`REQ-SUB-003` RB-6), para
 *    que la pantalla diga «1 de 5 usuarios» antes de que nadie choque con el
 *    techo, que es el momento en que la información sirve de algo;
 *  - **cada fila trae si se puede desactivar y por qué no**. Es lo que permite
 *    deshabilitar el botón del último `owner` con su motivo en vez de dejar
 *    que lo pulse y reciba un `422`. No sustituye a la comprobación —entre
 *    pintar y pulsar cabe otra petición, y por eso `LastOwnerGuard` vuelve a
 *    mirar con el bloqueo puesto— pero evita el callejón.
 */
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

    /**
     * Reenvía el enlace, invalidando el anterior (`REQ-ORG-004` RB-4, SP-6).
     */
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

    /**
     * Revoca una invitación pendiente y libera su cupo (RB-6).
     *
     * Es el `DELETE` del CRUD de equipo de [05 §7.4], y solo sirve para
     * invitaciones: a un miembro que ya entró se le desactiva, no se le borra
     * (`REQ-ORG-005` RB-1). Quien lo intente recibe `422` con el motivo desde
     * la acción.
     */
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

    /**
     * Promueve o degrada a alguien (`REQ-ORG-003` RB-3).
     *
     * El mensaje nombra el rol que queda y no el que se pidió: son lo mismo
     * salvo cuando la acción no escribió nada por venir ya con ese rol, y
     * entonces decir «ahora es Empleado» sigue siendo verdad.
     */
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
     * El equipo tal como lo necesita la pantalla (`TeamMember`).
     *
     * La consulta no lleva un solo `where` de organización: el global scope ya
     * la filtra (`REQ-ORG-002` RB-1). Y **los desactivados salen en la lista**,
     * marcados: esconderlos convertiría la baja lógica en un borrado a efectos
     * prácticos, y el `owner` dejaría de poder reactivar a nadie (RB-7).
     *
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
                'isPending' => $member->hasPendingInvitation(),
                'isSelf' => $actor instanceof User && $member->is($actor),
                'isLastOwner' => $this->couldBeTheLastOwner($member) && $this->guard->isLastActiveOwner($member),
            ])
            ->values()
            ->all();
    }

    /**
     * Indica si merece la pena preguntarle a la guarda por esta persona.
     *
     * `LastOwnerGuard::isLastActiveOwner()` cuesta una consulta, y un equipo
     * de quince la haría quince veces para catorce respuestas que se conocen
     * de antemano: quien no es `owner` activo no puede ser el último `owner`
     * activo. La regla sigue viviendo en la guarda —aquí no se cuenta a
     * nadie—; lo que se evita es preguntársela a quien no puede cumplirla.
     */
    private function couldBeTheLastOwner(User $member): bool
    {
        return $member->disabled_at === null && $member->role === OrganizationRole::Owner;
    }
}
