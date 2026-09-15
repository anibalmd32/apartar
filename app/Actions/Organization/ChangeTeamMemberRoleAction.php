<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\LastOwnerGuard;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Promueve o degrada a alguien del equipo (`REQ-ORG-003` RB-3).
 *
 * Es la vía que faltaba: `T-009` dejó la matriz de roles, la policy y el
 * bloqueo del último `owner`, pero no había forma de cambiarle el rol a nadie
 * desde el producto.
 *
 * **Promover y degradar no son simétricas**, y por eso el `match` de abajo
 * existe en vez de un `update()` a secas:
 *
 *  - **promover suma un `owner` activo**, así que no puede romper la
 *    invariante de RB-2 y no necesita bloqueo. Tampoco consume plan: la
 *    persona ya ocupaba su sitio, y el techo de `users` cuenta gente con
 *    acceso, no gente con galones;
 *  - **degradar resta uno**, que es la única dirección en la que una
 *    organización puede quedarse sin nadie al mando. Ahí entra
 *    `LastOwnerGuard`, que comprueba y escribe dentro de la misma transacción
 *    con las filas de los `owner` bloqueadas.
 *
 * **Un `owner` puede degradarse a sí mismo** (RB-3), y no es el caso de
 * `REQ-ORG-005` RB-4: aquella regla prohíbe *desactivarse*, que es quedarse
 * fuera; degradarse es seguir dentro con menos. Son cosas distintas y la única
 * barrera aquí es ser el último.
 *
 * Lo que hace que el cambio surta efecto **en el siguiente clic** y no en el
 * siguiente inicio de sesión (RB-6, SP-4) no está en este archivo: está en no
 * haber guardado nunca el rol en la sesión. El guard trae la fila de `users`
 * en cada petición y `EnsureIsOwner` mira su columna, así que basta con
 * escribir aquí.
 */
final class ChangeTeamMemberRoleAction
{
    public function __construct(private readonly LastOwnerGuard $guard) {}

    /**
     * @param  User  $member  a quien se le cambia el rol
     * @param  User  $actor  quien lo ordena
     *
     * @throws AuthorizationException si el actor no gobierna el equipo
     * @throws LastOwnerException si degradarlo dejaría a la organización sin `owner` activo (SP-2, SP-3)
     */
    public function handle(User $member, OrganizationRole $role, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $member);

        // Mismo rol: no se escribe nada. Un `UPDATE` que no cambia nada
        // ensuciaría la auditoría de `REQ-ORG-004` RB-7 con cambios de rol que
        // no ocurrieron, y haría pasar por la guarda —y por su bloqueo— a
        // quien no toca la invariante.
        if ($member->role === $role) {
            return $member;
        }

        return match ($role) {
            OrganizationRole::Owner => $this->promote($member),
            OrganizationRole::Member => $this->demote($member),
        };
    }

    /**
     * De `member` a `owner`: sin guarda, porque suma.
     */
    private function promote(User $member): User
    {
        $member->forceFill(['role' => OrganizationRole::Owner])->save();

        return $member;
    }

    /**
     * De `owner` a `member`: con el bloqueo puesto, porque resta.
     *
     * La escritura viaja como `Closure` y ocurre **dentro** de la transacción
     * del conteo. Comprobar en una transacción y escribir en otra deja
     * exactamente el hueco que el bloqueo cierra: dos `owner` degradándose a
     * la vez leerían ambos «hay dos» y la organización se quedaría sin
     * ninguno, que es un estado del que no se sale sin tocar la base de datos
     * a mano.
     *
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
