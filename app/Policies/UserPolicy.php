<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\User;

/**
 * Quién puede gobernar el equipo (`REQ-ORG-003` RB-1, área `Team`).
 *
 * Autoriza; **no** decide las reglas de negocio de cada operación. Que nadie
 * se desactive a sí mismo (`REQ-ORG-005` RB-4) o que la organización conserve
 * un `owner` (RB-2) no son permisos: son invariantes que se comprueban al
 * escribir, con bloqueo, en `LastOwnerGuard`. Mezclarlas aquí daría `403`
 * donde corresponde `422`, y peor, haría creer que una comprobación sin
 * transacción basta.
 *
 * `platform_users` no aparece por ninguna parte y no es un olvido (RB-5): los
 * operadores de la plataforma son otra tabla, otro guard y otro modelo
 * (`D-5`). Si algún día hiciera falta contemplarlos aquí, sería la señal de
 * que las dos identidades se han mezclado.
 */
final class UserPolicy
{
    /**
     * Ver el listado del equipo.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Team);
    }

    /**
     * Ver la ficha de un compañero.
     */
    public function view(User $user, User $member): bool
    {
        return $this->governsTeamOf($user, $member);
    }

    /**
     * Invitar a alguien nuevo (`REQ-ORG-004`).
     *
     * El límite del plan no se mira aquí: no tener sitio no es no tener
     * permiso, y la diferencia se nota en la respuesta —`422` con el plan que
     * lo desbloquea (`REQ-SUB-004` SP-1), no `403`—.
     */
    public function create(User $user): bool
    {
        return $user->role->can(OrganizationCapability::Team);
    }

    /**
     * Cambiar el rol de un compañero (`REQ-ORG-003` RB-3).
     *
     * Un `owner` puede ascender a un `member` y puede degradarse a sí mismo:
     * lo único que lo detiene es ser el último, y eso lo comprueba
     * `LastOwnerGuard` al escribir.
     */
    public function update(User $user, User $member): bool
    {
        return $this->governsTeamOf($user, $member);
    }

    /**
     * El actor gobierna el equipo **y** el afectado es de su organización.
     *
     * La segunda mitad no sobra: el aislamiento hace que un identificador
     * ajeno no se resuelva por la URL (`REQ-ORG-002` RB-6), pero una `Action`
     * que reciba el modelo por otra vía se quedaría sin esa red.
     */
    private function governsTeamOf(User $user, User $member): bool
    {
        return $user->organization_id === $member->organization_id
            && $user->role->can(OrganizationCapability::Team);
    }
}
