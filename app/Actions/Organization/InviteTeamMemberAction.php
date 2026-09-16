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

/**
 * El ciclo de vida de una invitación al equipo (`REQ-ORG-004`).
 *
 * Tres operaciones en un archivo —invitar, reenviar y revocar— porque son la
 * misma cosa en tres momentos y comparten lo que de verdad importa: **emitir
 * el enlace invalidando el anterior** (RB-4) y **mover el cupo del plan**
 * (RB-2 y RB-6). Repartidas en tres acciones, la segunda que alguien tocara
 * dejaría de ajustar el contador y nadie lo notaría hasta que un plan Free
 * admitiera a cincuenta personas.
 *
 * ## La invitación consume cupo desde que se envía
 *
 * RB-2 lo pide y la spec dice por qué: contando al aceptar, un plan Free
 * podría enviar cincuenta invitaciones y quedarse esperando a ver cuántas
 * pican. Aquí no hace falta sumar nada aparte para conseguirlo, y eso es una
 * propiedad del modelo y no un truco: el invitado **es** una fila de `users`
 * sin contraseña y sin desactivar desde el primer instante (RB-3), así que ya
 * la cuenta la definición de `users` de `REQ-SUB-003` RB-2. Su simétrica sale
 * gratis por el mismo motivo: revocar borra la fila y el cupo vuelve solo.
 *
 * ## Por qué revocar sí borra
 *
 * `REQ-ORG-005` RB-1 dice que un usuario **nunca** se borra, y tiene razón:
 * borrarlo se lleva por delante `reviewed_by`, `registered_by` y el rastro de
 * quién aprobó qué comprobante. Nada de eso existe en una invitación pendiente
 * —quien no ha entrado nunca no ha aprobado nada—, así que la regla protege
 * algo que aquí no hay. Lo que sí habría con una baja lógica es un correo
 * ocupado **para siempre en toda la plataforma** (`REQ-ORG-001` RB-3): quien
 * se equivoca de dirección al invitar no podría volver a usar la correcta, y
 * nadie entendería por qué.
 */
final class InviteTeamMemberAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * Invita a alguien nuevo y le manda su enlace.
     *
     * @param  User  $actor  quien invita; solo el `owner` puede (RB-1)
     *
     * @throws AuthorizationException si el actor no gobierna el equipo (SP-4)
     * @throws \App\Domain\Subscription\PlanLimitReachedException si no queda cupo (SP-1)
     * @throws ValidationException si el correo ya está usado en cualquier organización (SP-2)
     */
    public function handle(string $name, string $email, OrganizationRole $role, User $actor): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        $email = mb_strtolower(trim($email));

        [$member, $invitation] = DB::transaction(function () use ($name, $email, $role, $actor): array {
            // SP-1 · el cupo se comprueba con el contador bloqueado y **antes**
            // de crear, dentro de esta misma transacción. Dos invitaciones
            // simultáneas con un solo sitio se serializan aquí: una pasa y la
            // otra recibe el 422 con el plan que lo desbloquea.
            $this->limits->assertCanCreate(UsageMetric::Users);

            $invitation = TeamInvitation::issueFor($this->context->organization());

            $member = $this->createInvitedUser($name, $email, $role, $invitation);

            // El cupo se materializa en la misma transacción (`REQ-SUB-003`
            // RB-3): quien mire el equipo un instante después ve «2 de 5», no
            // el número de antes de invitar.
            $this->limits->synchronise(UsageMetric::Users);

            // RB-1 · quién abrió la puerta del negocio y a quién. `properties`
            // no lleva la huella del enlace: es lo que abre esa puerta, y RB-5
            // prohíbe tokens — `AuditProperties` la redactaría igualmente, pero
            // no dársela es mejor que confiar en que la tape.
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
     * Emite un enlace nuevo para una invitación que sigue pendiente.
     *
     * **El anterior deja de funcionar** (RB-4, SP-6), y no por una lista de
     * revocados sino porque solo se guarda un hash por fila: escribir el nuevo
     * borra el viejo. Es lo que hace que «reenviar» no multiplique las puertas
     * abiertas, que es el fallo clásico de los enlaces de un solo uso.
     *
     * No toca el cupo: la persona ya lo ocupaba.
     *
     * @throws AuthorizationException
     * @throws InvitationNotPendingException si esa persona ya aceptó
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
     * Revoca una invitación pendiente y **libera su unidad del límite** (RB-6).
     *
     * @throws AuthorizationException
     * @throws InvitationNotPendingException si esa persona ya aceptó
     */
    public function revoke(User $member, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $member);

        $this->assertStillPending($member);

        DB::transaction(function () use ($member, $actor): void {
            $member->delete();

            $this->limits->synchronise(UsageMetric::Users);

            // La fila se borra (RB-6 de `REQ-ORG-004`), así que **esta entrada
            // es todo lo que queda** de que esa invitación existió. Se escribe
            // después del borrado a propósito: el modelo conserva sus
            // atributos en memoria y la descripción se queda con el correo,
            // que es lo que alguien buscará («¿a quién invitamos y quién lo
            // deshizo?») cuando ya no haya fila que consultar.
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
     * Crea al invitado: existe, ocupa cupo y no puede entrar (RB-3).
     *
     * Nace **sin contraseña**, que es lo que lo mantiene fuera hasta que fije
     * la suya: la columna es nullable desde `T-008` justamente para esto, y
     * sembrar una contraseña aleatoria en su lugar sería una credencial viva
     * que nadie conoce pero que existe.
     *
     * @throws ValidationException si el correo ya está usado en cualquier organización
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
                // Quien fija su propia contraseña al entrar no tiene ninguna
                // que rotar: la exigencia de cambio es para las asignadas por
                // otro (`REQ-BOF-001` RB-5) y para la reactivación
                // (`REQ-ORG-005` RB-6).
                'must_change_password' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // SP-2 · `users.email` es único en toda la plataforma
            // (`REQ-ORG-001` RB-3). El `FormRequest` ya lo comprueba y da un
            // mensaje junto al campo; esto es el índice, que es lo único que
            // cierra la ventana entre aquella consulta y este `INSERT`.
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya tiene una cuenta de equipo en Apartar. Usa otra dirección.',
            ]);
        }
    }

    /**
     * Manda el enlace, una vez confirmada la transacción.
     *
     * Fuera del `DB::transaction` a propósito: un correo con el enlace de una
     * invitación que después se deshace es irrecuperable —no hay forma de
     * desenviarlo— y lleva a alguien a una pantalla que no reconoce a su
     * token. El pedido de `REQ-ORG-004` es que la invitación exista **y** se
     * comunique, en ese orden.
     */
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
