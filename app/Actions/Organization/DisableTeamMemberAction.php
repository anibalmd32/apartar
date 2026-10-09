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

/**
 * Retira el acceso de alguien sin borrar su rastro (`REQ-ORG-005`).
 *
 * **La baja es lógica y nunca un borrado** (RB-1). Un usuario borrado se lleva
 * por delante `reviewed_by`, `registered_by` y su entrada en la auditoría: es
 * decir, quién aprobó cada comprobante de pago de este negocio. El día que
 * haya una discusión con un cliente sobre un pago, la respuesta no puede ser
 * «esa persona ya no trabaja aquí y no sabemos quién lo aprobó». Por eso se
 * escribe `disabled_at` y la fila se queda donde está, con su nombre (RB-7).
 *
 * Tres comprobaciones, y ninguna sustituye a las otras:
 *
 *  1. **permiso** —solo el `owner` gobierna el equipo—, que es `403`;
 *  2. **no eres tú** (RB-4), que es `422`;
 *  3. **queda un `owner`** (RB-3), que es `422` y va **con bloqueo, dentro de
 *     la transacción de la escritura**, porque es la única que dos peticiones
 *     simultáneas pueden burlar. De eso se encarga `LastOwnerGuard`, que es
 *     donde vive el mecanismo desde `T-009`.
 *
 * El orden importa: primero lo barato y lo que no depende de nadie más, y el
 * bloqueo al final, porque serializar peticiones para después rechazarlas por
 * permisos sería pagar el precio caro por la respuesta fácil.
 */
final class DisableTeamMemberAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly LastOwnerGuard $guard,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @param  User  $member  a quien se da de baja
     * @param  User  $actor  quien la ordena
     *
     * @throws AuthorizationException si el actor no gobierna el equipo
     * @throws CannotDisableSelfException si se intenta desactivar a sí mismo (SP-1)
     * @throws LastOwnerException si es el último `owner` activo (SP-2, SP-3)
     */
    public function handle(User $member, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $member);

        if ($member->is($actor)) {
            throw CannotDisableSelfException::make();
        }

        // Ya desactivado: no hay nada que hacer y repetirlo movería
        // `disabled_at` a la fecha de hoy, borrando cuándo se fue de verdad.
        if ($member->disabled_at !== null) {
            return $member;
        }

        // El contador primero, **antes** que las filas de los `owner` que
        // coge la guarda: invitar coge el contador y después inserta en el
        // hueco de esas filas, y con el orden al revés las dos peticiones se
        // esperaban en cruz (`LimitEnforcer::lockCounter()`).
        return DB::transaction(fn (): User => $this->disable($member, $actor));
    }

    private function disable(User $member, User $actor): User
    {
        $this->limits->lockCounter(UsageMetric::Users);

        return $this->guard->whileKeepingAnOwner($member, 'desactivar', function () use ($member, $actor): User {
            // Releída con bloqueo, por lo mismo que la reactivación: dos bajas
            // simultáneas liberarían dos unidades por una persona.
            $locked = User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            ModelState::adopt($member, $locked->getAttributes());

            if ($member->disabled_at !== null) {
                return $member;
            }

            $member->forceFill(['disabled_at' => now()])->save();

            // RB-5 · la baja **libera** su unidad del límite `users`, en la
            // misma transacción del bloqueo. La sesión abierta de esa persona
            // cae en su siguiente petición sin que nadie más haga nada:
            // `EnsureAdminIsActive` mira esta columna en cada una (RB-2).
            $this->limits->adjust(UsageMetric::Users, -1);

            // RB-1 · quitarle el acceso a alguien es de las cosas que el dueño
            // va a querer poder fechar: «desde cuándo no entra» es la primera
            // pregunta cuando aparece algo raro hecho por esa cuenta.
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
